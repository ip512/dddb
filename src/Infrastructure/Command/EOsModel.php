<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class EOsModel
{
    public string $vendor;
    #[Context(denormalizationContext: [ObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true])]
    public string $name;
    /** @internal use getModels() method */
    public string|array $models = [] {
        get => \is_string($this->models) ? [$this->models] : $this->models;
        set(array|string $value) {
            $this->models = \is_string($value) ? [$value] : $value;
        }
    }
    public string $codename;
    #[SerializedName('build_version_dev')]
    public ?string $buildVersionDev = null;
    #[SerializedName('build_version_stable')]
    public ?string $buildVersionStable = null;
}
