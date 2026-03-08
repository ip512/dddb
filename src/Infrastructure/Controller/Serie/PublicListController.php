<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller\Serie;

use App\Application\QueryBusInterface;
use App\Application\Serie\Query\AllSeriesQuery;
use App\Application\Serie\View\SerieHeader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PublicListController
{
    public function __construct(
        private \Twig\Environment $twig,
        private QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/', name: 'app_series_public_list', methods: ['GET'])]
    public function __invoke(): Response
    {
        $series = $this->queryBus->handle(
            new AllSeriesQuery(),
        );

        return new Response(
            content: $this->twig->render(
                name: 'public/index.html.twig',
                context: [
                    'series' => $this->aggregateByManufacturer($series),
                ],
            ),
        );
    }

    /**
     * @param array<SerieHeader> $series
     *
     * @return array<int,array{manufacturer:string,series:array<SerieHeader>}>
     */
    private function aggregateByManufacturer(array $series): array
    {
        $aggregate = [];
        $previousManufacturer = null;
        $index = 0;

        foreach ($series as $serie) {
            if ($previousManufacturer !== $serie->manufacturer) {
                $aggregate[++$index]['manufacturer'] = $serie->manufacturer;
            }
            $aggregate[$index]['series'][] = $serie;
            $previousManufacturer = $serie->manufacturer;
        }

        return $aggregate;
    }
}
