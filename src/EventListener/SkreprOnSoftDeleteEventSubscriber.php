<?php

declare(strict_types=1);

/*
 * This file is a part of the StichtingSD/SoftDeleteableExtensionBundle
 * (c) StichtingSD <info@stichtingsd.nl> https://stichtingsd.nl
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Skrepr\SkreprSoftDeleteableExtensionBundle\EventListener;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;
use StichtingSD\SoftDeleteableExtensionBundle\Exception\SoftDeletePropertyAccessorNotFoundException;
use StichtingSD\SoftDeleteableExtensionBundle\Mapping\MetadataFactory;
use StichtingSD\SoftDeleteableExtensionBundle\Mapping\Type;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\PropertyAccess\PropertyAccess;

class SkreprOnSoftDeleteEventSubscriber
{
    use ContainerAwareTrait;

    public function __construct(
        private MetadataFactory $metadataFactory
    ) {
    }

    public function preSoftDelete(LifecycleEventArgs $args): void
    {
        $objectManager = $args->getObjectManager();
        $eventObject = $args->getObject();

        if (!$this->metadataFactory->hasCachedMetadataForClass($eventObject::class)) {
            $this->metadataFactory->computeMetadata($objectManager);
        }

        $metaData = $this->metadataFactory->getCachedMetadataForClass($eventObject::class);
        if (empty($metaData)) {
            return;
        }

        foreach ($metaData as $cachedProperty) {
            $type = Type::from($cachedProperty['type']);
            $associationMappingType = $cachedProperty['associationMappingType'];

            // ManyToMany is always CASCADE with one but. We only want to remove the association itself instead of the other entity.
            // This is because the other entity can still be associated to other objects and by removing the associated object could cause unintended removals.
            if (ClassMetadataInfo::MANY_TO_MANY === $associationMappingType && Type::REMOVE_ASSOCIATION_ONLY === $type) {
                $this->removeAssociationsFromManyToMany(
                    $eventObject,
                    $cachedProperty,
                    $objectManager
                );

                // We must continue because we don't want to soft-delete the target many-to-many object, only its association.
                continue;
            }

            match ($type) {
                Type::SET_NULL => $this->setNullAssociatedObjects(
                    $eventObject,
                    $cachedProperty,
                    $objectManager
                ),
                Type::CASCADE => $this->cascadeAssociatedObjects(
                    $eventObject,
                    $cachedProperty,
                    $objectManager
                )
            };
        }
    }

    private function removeAssociationsFromManyToMany(object $eventObject, array $metaData, ObjectManager $objectManager): void
    {
        $propertyAccessor = PropertyAccess::createPropertyAccessor();

        // Unidirectional defined the ManyToMany on one side only, so there is no inversedBy or mappedBy
        // Because unidirectional is always defined on the owning side.
        if ($metaData['isUnidirectional']) {
            // IMPORTANT! TODO! BUG!
            // Currently a bug and I don't understand why the code bellow doesn't work.
            // For some reason, the query executed bellow does return an entity but the associated entity
            // is removed from the collection, thus I can't remove it using removeElement(). Weird.
            // I understand why, Doctrine probably removes the ManyToMany relation automaticly, and Gedmo reverts this deletion.
            // Well, I've tried using the POST_SOFT_DELETE event but that does not matter.
            // See SoftDeleteManyToManyTest (skipped one's).
            // Call $objectManager->clear(); and add a count and you see it works.
            $associatedObjects = $objectManager->createQueryBuilder()
                ->select('e')
                ->from($metaData['associatedTo'], 'e')
                ->innerJoin(sprintf('e.%s', $metaData['associatedToProperty']), 'association')
                ->addSelect('association')
                ->andWhere(sprintf(':entity MEMBER OF e.%s', $metaData['associatedToProperty']))
                ->setParameter('entity', $eventObject)
                ->getQuery()
                ->getResult()
            ;

            // For BULK deleting this is the best option we've got.
            // But it's too risky since we're grabbing the first joinColumn.
            // Executing plain SQL queries is highly discouraged by Doctrine.
            // $connection = $objectManager->getConnection();
            // $joinTableName = $associationMapping['joinTable']['name'] ?? null;
            // $inverseColumnName = $associationMapping['joinTable']['joinColumns'][0]['name'] ?? null;
            // $statement = $connection->prepare(sprintf('DELETE FROM %s WHERE %s IN (%s)', $joinTableName, $inverseColumnName, implode(',', $objectsAssociated)));
            // $statement->execute();

            $uow = $objectManager->getUnitOfWork();
            // For now, just loop all the related entities and remove it from the collection.
            foreach ($associatedObjects as $object) {
                // Gedmo handles re-computation for the removed item but not for the related items.
                // Since doctrine by default removed the many-to-many association on removal and Gedmo only re-computes the deleted entity.
                // It doesn't revert the changes made in the parent entity.
                $meta = $objectManager->getClassMetadata($object::class);
                $uow->computeChangeSet($meta, $object);

                $association = $propertyAccessor->getValue($object, $metaData['associatedToProperty']);
                $association->removeElement($eventObject);
            }

            return;
        }

        try {
            $collection = $propertyAccessor->getValue($eventObject, $metaData['targetEntityProperty']);
            $collection->clear();
        } catch (\Exception $e) {
            throw new SoftDeletePropertyAccessorNotFoundException(sprintf('No accessor found for %s in %s', $metaData['associatedToProperty'], $eventObject::class), previous: $e);
        }
    }

    private function setNullAssociatedObjects(object $eventObject, array $metaData, ObjectManager $objectManager): void
    {
        $className = $metaData['associatedTo'];
        $propertyName = $metaData['associatedToProperty'];

        // Grab all the id's that are going to be updated, so we can schedule them for update.
        $objectsAssociatedToEventObject = $objectManager->createQueryBuilder()
            ->select('e.id')
            ->from($metaData['associatedTo'], 'e')
            ->andWhere("e.{$propertyName} = :eventObject")
            ->setParameter('eventObject', $eventObject)
            ->getQuery()
            ->getSingleColumnResult()
        ;

        // Actually update the entities, doing it this way won't cause memory problems.
        $objectManager->createQueryBuilder()
            ->update($metaData['associatedTo'], 'e')
            ->set("e.{$propertyName}", ':relation')
            ->andWhere("e.{$propertyName} = :eventObject")
            ->setParameter('eventObject', $eventObject)
            ->setParameter('relation', null)
            ->getQuery()
            ->execute()
        ;

        /**
         * @var UnitOfWork $uow
         */
        $uow = $objectManager->getUnitOfWork();
        // Use the getReference() method to fetch a partial object for each entity
        foreach ($objectsAssociatedToEventObject as $id) {
            $objectProxy = $objectManager->getReference($className, $id);
            $uow->scheduleExtraUpdate($objectProxy, [
                $propertyName => [$eventObject, null],
            ]);
        }
    }

    private function cascadeAssociatedObjects(object $eventObject, array $metaData, ObjectManager $objectManager): void
    {
        // Field name is set in the targetEntity class, when Entity1 as #[onSoftDelete()] on a property.
        // We should grab the SoftDelete fieldName from Gedmo.
        $className = $metaData['associatedTo'];
        $propertyName = $metaData['associatedToProperty'];
        $fieldName = $metaData['targetEntitySoftDeleteFieldName'];

        // Actually update the entities, doing it this way won't cause memory problems.
        $deletedAt = new \DateTimeImmutable();
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
            ->setParameter('eventObject', $eventObject->getId()->toBinary())
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
