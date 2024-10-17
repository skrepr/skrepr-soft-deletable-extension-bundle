<?php

namespace Skrepr\SkreprSoftDeleteableExtensionBundle\EventListener;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;

class SkreprOnSoftDeleteEventSubscriber
{
    protected function cascadeAssociatedObjects(object $eventObject, array $metaData, ObjectManager $objectManager): void
    {
        // Field name is set in the targetEntity class, when Entity1 as #[onSoftDelete()] on a property.
        // We should grab the SoftDelete fieldName from Gedmo.
        $className = $metaData['associatedTo'];
        $propertyName = $metaData['associatedToProperty'];
        $fieldName = $metaData['targetEntitySoftDeleteFieldName'];

        // Actually update the entities, doing it this way won't cause memory problems.
        $deletedAt = new \DateTimeImmutable();
        var_dump('A1');
        $objectManager->createQueryBuilder()
            ->update($metaData['associatedTo'], 'e')
            ->set("e.{$fieldName}", ':deletedAt')
            ->andWhere("e.{$propertyName} = :eventObject")
            ->andWhere("e.{$fieldName} IS NULL")
            ->setParameter('eventObject', $eventObject->getId()->toBinary())
            ->setParameter('deletedAt', $deletedAt->format('Y-m-d H:i:s'))
            ->getQuery()
            ->execute()
        ;

        // Grab all the id's that are going to be updated, so we can schedule them for update.
        $objectsAssociatedToEventObject = $objectManager->createQueryBuilder()
            ->select('e.id')
            ->from($metaData['associatedTo'], 'e')
            ->andWhere("e.{$propertyName} = :eventObject")
            ->setParameter('eventObject', $eventObject)
            ->getQuery()
            ->toIterable(hydrationMode: AbstractQuery::HYDRATE_ARRAY)
        ;

        /**
         * @var UnitOfWork $uow
         */
        $uow = $objectManager->getUnitOfWork();
        // Use the getReference() method to fetch a partial object for each entity
        foreach ($objectsAssociatedToEventObject as $row) {
            $id = $row['id'] ?? null;

            if (null === $id) {
                continue;
            }

            $objectProxy = $objectManager->getReference($className, $id);
            $objectManager->getEventManager()->dispatchEvent(
                GedmoSoftDeleteableListener::PRE_SOFT_DELETE,
                new LifecycleEventArgs($objectProxy, $objectManager)
            );

            $uow->scheduleExtraUpdate($objectProxy, [
                $fieldName => [null, $deletedAt],
            ]);

            $objectManager->getEventManager()->dispatchEvent(
                GedmoSoftDeleteableListener::POST_SOFT_DELETE,
                new LifecycleEventArgs($objectProxy, $objectManager)
            );
        }
    }
}