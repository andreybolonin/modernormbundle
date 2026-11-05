<?php

declare(strict_types=1);

namespace ModernORM\Bundle\Fixtures;

final class ReferenceRepository extends \Doctrine\Common\DataFixtures\ReferenceRepository
{
    protected function getIdentifier(object $reference, object $uow): mixed
    {
        return $this->getManager()->getClassMetadata($reference::class)->getIdentifierValues($reference);
    }

    /** Capture generated identifiers after each flush, before objects are detached. */
    public function captureIdentities(): void
    {
        foreach ($this->getReferencesByClass() as $class => $references) {
            foreach ($references as $name => $reference) {
                if (!$this->getManager()->contains($reference) && !$this->hasIdentity((string) $name, $class)) {
                    throw new \LogicException('Fixture reference was not persisted: '.$name);
                }
                $identity = $this->getManager()->getClassMetadata($class)->getIdentifierValues($reference);
                if ([] === $identity) {
                    throw new \LogicException('Fixture reference has no persisted identifier: '.$name);
                }
                $this->setReferenceIdentity((string) $name, $identity, $class);
            }
        }
    }
}
