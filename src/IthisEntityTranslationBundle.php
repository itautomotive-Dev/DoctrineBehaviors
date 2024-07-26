<?php

declare(strict_types=1);

namespace Ithis\Bundle\EntityTranslation;

use Ithis\Bundle\EntityTranslation\DependencyInjection\IthisEntityTranslationExtension;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class IthisEntityTranslationBundle extends Bundle
{
    public function getContainerExtension(): Extension
    {
        return new IthisEntityTranslationExtension();
    }
}
