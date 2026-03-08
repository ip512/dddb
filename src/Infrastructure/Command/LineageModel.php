<?php

declare(strict_types=1);

namespace App\Infrastructure\Command;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class LineageModel
{
    public string $vendor;
    public string $name;
    public array $models = [];
    public string $codename;
    public int $variant = 0;
    #[SerializedName('current_branch')]
    #[Context(denormalizationContext: [ObjectNormalizer::DISABLE_TYPE_ENFORCEMENT => true])]
    public string $currentBranch;
}
