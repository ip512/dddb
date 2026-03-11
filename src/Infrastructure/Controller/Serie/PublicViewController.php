<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Serie;

use App\Application\Model\Query\ListSerieModelsQuery;
use App\Application\Model\Query\ModelQuery;
use App\Application\Model\View\ModelHeader;
use App\Application\QueryBusInterface;
use App\Domain\Model\Exception\ModelNotFoundException;
use App\Domain\Model\Serie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PublicViewController
{
    public function __construct(
        private \Twig\Environment $twig,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/public/device/{slug}/{serie}/{modelUuid?}', name: 'app_series_public_view', methods: ['GET'])]
    public function __invoke(Serie $serie, ?string $modelUuid): Response
    {
        /** @var ModelHeader[] $modelHeaders */
        $modelHeaders = $this->queryBus->handle(new ListSerieModelsQuery($serie));

        $isDefaultModel = false;
        if (\is_null($modelUuid)) {
            $modelUuid = $modelHeaders[0]->uuid;
            $isDefaultModel = true;
        }
        try {
            $model = $this->queryBus->handle(new ModelQuery($modelUuid));
        } catch (ModelNotFoundException $e) {
            return new Response($e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return new Response(
            content: $this->twig->render(
                name: 'public/models/view.html.twig',
                context: [
                    'serie' => $serie,
                    'models' => $modelHeaders,
                    'selectedModel' => $model,
                    'isDefaultModel' => $isDefaultModel,
                ],
            ),
        );
    }
}
