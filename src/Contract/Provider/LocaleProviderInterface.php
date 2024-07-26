<?php

declare(strict_types=1);

namespace Ithis\Bundle\EntityTranslation\Contract\Provider;

interface LocaleProviderInterface
{
    public function provideCurrentLocale(): ?string;

    public function provideFallbackLocale(): ?string;
}
