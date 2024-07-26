<?php

declare(strict_types=1);

namespace Ithis\Bundle\EntityTranslation\Model\Translatable;

use Ithis\Bundle\EntityTranslation\Contract\Entity\TranslatableInterface;

trait TranslationPropertiesTrait
{
    /**
     * @var string
     */
    protected $locale;

    /**
     * Will be mapped to translatable entity by TranslatableSubscriber
     *
     * @var TranslatableInterface
     */
    protected $translatable;
}
