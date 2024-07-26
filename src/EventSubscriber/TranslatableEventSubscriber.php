<?php

declare(strict_types=1);

namespace Ithis\Bundle\EntityTranslation\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use Ithis\Bundle\EntityTranslation\Contract\Entity\TranslatableInterface;
use Ithis\Bundle\EntityTranslation\Contract\Entity\TranslationInterface;
use Ithis\Bundle\EntityTranslation\Contract\Provider\LocaleProviderInterface;
use ReflectionClass;
use ReflectionException;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::prePersist)]
final readonly class TranslatableEventSubscriber
{
    public const LOCALE = 'locale';

    private int $translatableFetchMode;

    private int $translationFetchMode;

    public function __construct(
        private LocaleProviderInterface $localeProvider,
        string $translatableFetchMode,
        string $translationFetchMode
    ) {
        $this->translatableFetchMode = $this->convertFetchString($translatableFetchMode);
        $this->translationFetchMode = $this->convertFetchString($translationFetchMode);
    }

    /**
     * Adds mapping to the translatable and translations.
     * @throws ReflectionException
     * @throws MappingException
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $loadClassMetadataEventArgs): void
    {
        $classMetadata = $loadClassMetadataEventArgs->getClassMetadata();
        if (! $classMetadata->reflClass instanceof ReflectionClass) {
            // Class has not yet been fully built, ignore this event
            return;
        }

        if ($classMetadata->isMappedSuperclass) {
            return;
        }

        if (is_a($classMetadata->reflClass->getName(), TranslatableInterface::class, true)) {
            $this->mapTranslatable($classMetadata);
        }

        if (is_a($classMetadata->reflClass->getName(), TranslationInterface::class, true)) {
            $this->mapTranslation($classMetadata, $loadClassMetadataEventArgs->getObjectManager());
        }
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $lifecycleEventArgs
     * @return void
     */
    public function postLoad(LifecycleEventArgs $lifecycleEventArgs): void
    {
        $this->setLocales($lifecycleEventArgs);
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $lifecycleEventArgs
     * @return void
     */
    public function prePersist(LifecycleEventArgs $lifecycleEventArgs): void
    {
        $this->setLocales($lifecycleEventArgs);
    }

    /**
     * Convert string FETCH mode to required string
     */
    private function convertFetchString(string|int $fetchMode): int
    {
        if (is_int($fetchMode)) {
            return $fetchMode;
        }

        if ($fetchMode === 'EAGER') {
            return ClassMetadata::FETCH_EAGER;
        }

        if ($fetchMode === 'EXTRA_LAZY') {
            return ClassMetadata::FETCH_EXTRA_LAZY;
        }

        return ClassMetadata::FETCH_LAZY;
    }

    /**
     * @param ClassMetadata<TranslatableInterface> $classMetadataInfo
     * @return void
     * @throws ReflectionException
     */
    private function mapTranslatable(ClassMetadata $classMetadataInfo): void
    {
        if ($classMetadataInfo->hasAssociation('translations')) {
            return;
        }

        $classMetadataInfo->mapOneToMany([
            'fieldName' => 'translations',
            'mappedBy' => 'translatable',
            'indexBy' => self::LOCALE,
            'cascade' => ['persist', 'remove'],
            'fetch' => $this->translatableFetchMode,
            'targetEntity' => $classMetadataInfo->getReflectionClass()
                ->getMethod('getTranslationEntityClass')
                ->invoke(null),
            'orphanRemoval' => true,
        ]);
    }

    /**
     * @param ClassMetadata<TranslatableInterface> $classMetadataInfo
     * @param ObjectManager $objectManager
     * @return void
     * @throws ReflectionException
     * @throws MappingException
     */
    private function mapTranslation(ClassMetadata $classMetadataInfo, ObjectManager $objectManager): void
    {
        if (! $classMetadataInfo->hasAssociation('translatable')) {
            $targetEntity = $classMetadataInfo->getReflectionClass()
                ->getMethod('getTranslatableInterfaceClass')
                ->invoke(null);

            /** @var ClassMetadata<TranslatableInterface> $classMetadata */
            $classMetadata = $objectManager->getClassMetadata($targetEntity);//@phpstan-ignore-line

            $singleIdentifierFieldName = $classMetadata->getSingleIdentifierFieldName();

            $classMetadataInfo->mapManyToOne([
                'fieldName' => 'translatable',
                'inversedBy' => 'translations',
                'cascade' => ['persist'],
                'fetch' => $this->translationFetchMode,
                'joinColumns' => [[
                    'name' => 'translatable_id',
                    'referencedColumnName' => $singleIdentifierFieldName,
                    'onDelete' => 'CASCADE',
                ]],
                'targetEntity' => $targetEntity,
            ]);
        }

        $name = $classMetadataInfo->getTableName() . '_unique_translation';
        if (
            ! $this->hasUniqueTranslationConstraint($classMetadataInfo, $name) &&
            $classMetadataInfo->getName() === $classMetadataInfo->rootEntityName
        ) {
            $classMetadataInfo->table['uniqueConstraints'][$name] = [
                'columns' => ['translatable_id', self::LOCALE],
            ];
        }

        if (! $classMetadataInfo->hasField(self::LOCALE) && ! $classMetadataInfo->hasAssociation(self::LOCALE)) {
            $classMetadataInfo->mapField([
                'fieldName' => self::LOCALE,
                'type' => 'string',
                'length' => 5,
            ]);
        }
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $lifecycleEventArgs
     * @return void
     */
    private function setLocales(LifecycleEventArgs $lifecycleEventArgs): void
    {
        $entity = $lifecycleEventArgs->getObject();
        if (! $entity instanceof TranslatableInterface) {
            return;
        }

        $currentLocale = $this->localeProvider->provideCurrentLocale();
        if ($currentLocale) {
            $entity->setCurrentLocale($currentLocale);
        }

        $fallbackLocale = $this->localeProvider->provideFallbackLocale();
        if ($fallbackLocale) {
            $entity->setDefaultLocale($fallbackLocale);
        }
    }

    /**
     * @param ClassMetadata<TranslatableInterface> $classMetadataInfo
     * @param string $name
     * @return bool
     */
    private function hasUniqueTranslationConstraint(ClassMetadata $classMetadataInfo, string $name): bool
    {
        return isset($classMetadataInfo->table['uniqueConstraints'][$name]);
    }
}
