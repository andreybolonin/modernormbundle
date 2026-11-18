<?php

declare(strict_types=1);

namespace ModernORM\Bundle\DataTransfer;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class SuggestionWizard
{
    public static function checkOutput(string $path): void
    {
        if ($path === '' || str_contains($path, '://') || file_exists($path) || is_link($path) || !is_dir(dirname($path)) || !is_writable(dirname($path))) {
            throw new \InvalidArgumentException('Rules output must be a new local file in an existing directory; existing rules are never overwritten.');
        }
    }

    public function run(SymfonyStyle $io, TransformationRules $existing, array $evidence, string $path, array $plan): array
    {
        $io->title('Suggested transformation rules');
        foreach ($evidence['issues'] as $issue) {
            $io->text(sprintf('%s.%s: %s (%d values)', $issue['table'], $issue['column'], $issue['error'], $issue['count']));
        }
        $io->note('Review schema/source fixes for other errors. No truncation, deduplication or FK remapping is performed. UNIQUE/FK/CHECK and triggers require a separate rehearsal.');
        $document = $existing->document();
        $ids = array_fill_keys(array_column($document['rules'], 'id'), true);
        $selected = 0;
        foreach ($evidence['candidates'] as $candidate) {
            $label = match ($candidate['kind']) {
                'trim' => 'leading/trailing whitespace',
                'null_required' => 'NULL in a required target column',
                'zero_nullable' => 'zero date '.json_encode($candidate['from'], JSON_THROW_ON_ERROR).' (target allows NULL)',
                default => 'zero date '.json_encode($candidate['from'], JSON_THROW_ON_ERROR).' (target requires a value)',
            };
            $io->section(sprintf('%s.%s — %s: %d values', $candidate['table'], $candidate['column'], $label, $candidate['count']));
            $options = match ($candidate['kind']) {
                'trim' => ['Skip', 'Add trim rule', 'Fix source/schema manually'],
                'zero_nullable' => ['Skip', 'Replace zero date with NULL', 'Enter a replacement value', 'Fix source/schema manually'],
                default => ['Skip', 'Enter a replacement value', 'Fix source/schema manually'],
            };
            $choice = $io->choice('Choose an action', $options, 0);
            if ($choice === 'Skip' || $choice === 'Fix source/schema manually') {
                continue;
            }
            $id = 'suggest_'.substr(hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR)), 0, 16);
            $base = $id;
            for ($suffix = 2; isset($ids[$id]); ++$suffix) {
                $id = $base.'_'.$suffix;
            }
            $ids[$id] = true;
            $rule = ['id' => $id, 'table' => $candidate['table'], 'column' => $candidate['column']];
            if ($choice === 'Add trim rule') {
                $rule += ['operation' => 'trim'];
            } else {
                $value = $choice === 'Replace zero date with NULL' ? null : $io->ask('Replacement as a non-null JSON scalar (quote strings and decimals)', null, static function (mixed $answer): mixed {
                    if (!is_string($answer)) {
                        throw new \InvalidArgumentException('Enter a JSON string, integer or boolean.');
                    }
                    try {
                        $value = json_decode($answer, true, flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                    } catch (\JsonException) {
                        throw new \InvalidArgumentException('Invalid JSON scalar; quote strings.');
                    }
                    if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                        throw new \InvalidArgumentException('Use a non-null string, integer or boolean; quote decimal values.');
                    }

                    return $value;
                });
                $rule += $candidate['kind'] === 'null_required' ? ['operation' => 'null_to', 'value' => $value] : ['operation' => 'replace', 'from' => $candidate['from'], 'to' => $value];
            }
            $document['rules'][] = $rule;
            ++$selected;
        }
        if ($selected === 0) {
            $io->text('No rules selected; no file created.');

            return ['status' => 'no_rules_selected', 'selected_rules' => 0, 'rules' => null];
        }
        $rules = TransformationRules::fromDocument($document);
        if ($rules->errorsForPlan($plan) !== []) {
            throw new \InvalidArgumentException(implode(' ', $rules->errorsForPlan($plan)));
        }
        $io->section('Review complete rules file (existing rules plus selected rules)');
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $io->writeln($json, OutputInterface::OUTPUT_RAW);
        if (!$io->confirm('Save to '.$path.' and revalidate? No database writes will occur.', false)) {
            $io->text('Save declined; no file created. Existing rules remain active.');
            return ['status' => 'declined', 'selected_rules' => $selected, 'rules' => null];
        }
        self::checkOutput($path);
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException('Cannot exclusively create proposed rules file.');
        }
        try {
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new \RuntimeException('Incomplete rules file write; inspect the file before use.');
            }
        } finally {
            fclose($handle);
        }
        $io->text('Rules saved. Revalidating in the same read-only snapshot; no rehearsal will run.');

        return ['status' => 'saved', 'selected_rules' => $selected, 'output' => $path, 'rules' => $rules];
    }
}
