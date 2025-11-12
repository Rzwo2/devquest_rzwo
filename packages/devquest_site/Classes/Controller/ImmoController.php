<?php

declare(strict_types=1);

namespace Mbx\DevquestSite\Controller;

use Mbx\DevquestSite\Domain\Repository\ImmoRepository;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class ImmoController extends ActionController
{
    public function __construct(
        private readonly ImmoRepository $immoRepository,
    ) {}

    public function listAction(): ResponseInterface
    {
        $serializer = new Serializer([new ObjectNormalizer()], [new JsonEncoder()]);

        $immos = $this->immoRepository->findAll()->toArray();

        $jsonContent = $serializer->serialize($immos, 'json', [
            'groups' => ['api'],
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['pid', 'uid'],
        ]);

        return $this->jsonResponse($jsonContent);
    }
}
