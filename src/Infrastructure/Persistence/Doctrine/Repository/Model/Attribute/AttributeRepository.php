<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine\Repository\Model\Attribute;

use App\Application\Attribute\Normalizer\NormalizerInterface;
use App\Application\Attribute\View\AttributeFlatView;
use App\Domain\Model\Attribute\AttributeCollection;
use App\Domain\Model\Attribute\AttributeInterface;
use App\Domain\Model\Attribute\AttributeRepositoryInterface;
use App\Domain\Model\Attribute\Battery;
use App\Domain\Model\Attribute\Memo;
use App\Domain\Model\Attribute\SupportedOsList;
use App\Domain\Model\Model;
use App\Infrastructure\Persistence\Doctrine\Repository\Model\ModelRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

class AttributeRepository implements AttributeRepositoryInterface
{
    public function __construct(
        private readonly AttributeBuilder $attributeBuilder,
        private readonly ModelRepository $modelRepository,
        #[AutowireLocator(NormalizerInterface::class, defaultIndexMethod: 'supports')]
        private readonly ContainerInterface $attributeNormalizerLocator,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function getModelAttributes(Model $model): AttributeCollection
    {
        return $this->attributeBuilder->createAttributeCollection($model->getAttributes());
    }

    public function getAllAttributeNames(): array
    {
        return [
            Memo::NAME,
            SupportedOsList::NAME,
            Battery::NAME,
        ];
    }

    public function createAttributeFromModel(Model $model, string $attributeName): ?AttributeInterface
    {
        return $this->attributeBuilder->createAttributeFromModel($model, $attributeName);
    }

    public function updateModelAttribute(Model $model, string $attributeName, AttributeInterface $attribute): void
    {
        $attributes = $model->getAttributes();
        $attributes[$attributeName] = $this->attributeNormalizerLocator->get($attributeName)->normalize($attribute);
        $model->setAttributes($attributes);

        $this->modelRepository->update($model);
    }

    /**
     * @return iterable<AttributeFlatView>
     */
    public function findAllAttributes(): iterable
    {
        $models = $this->modelRepository->findAllAttributesIterable();

        foreach ($models as $model) {
            foreach ($model['attributes'] as $name => $value) {
                yield new AttributeFlatView(
                    $model['uuid'],
                    $name,
                    $this->serializer->serialize($value, JsonEncoder::FORMAT),
                );
            }
        }
    }
}
