<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/users', name: 'api_users_')]
class UserController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        summary: 'List users with pagination and search',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Search by name, surname or email',
                in: 'query',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of users',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'name', type: 'string'),
                                    new OA\Property(property: 'surname', type: 'string'),
                                    new OA\Property(property: 'email', type: 'string'),
                                    new OA\Property(
                                        property: 'roles',
                                        type: 'array',
                                        items: new OA\Items(type: 'string')
                                    ),
                                    new OA\Property(property: 'isVerified', type: 'boolean'),
                                    new OA\Property(
                                        property: 'verifiedAt',
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true
                                    ),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'page', type: 'integer'),
                        new OA\Property(property: 'limit', type: 'integer'),
                    ],
                    type: 'object'
                )
            )
        ]
    )]
    public function index(
        Request $request,
        UserRepository $userRepository
    ): JsonResponse {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $search = trim((string) $request->query->get('search', ''));

        $qb = $userRepository->createQueryBuilder('u');

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';

            $qb
                ->andWhere(
                    'LOWER(u.name) LIKE :term
                     OR LOWER(u.surname) LIKE :term
                     OR LOWER(u.email) LIKE :term'
                )
                ->setParameter('term', $term);
        }

        $qb->orderBy('u.id', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $items = [];
        /** @var User $user */
        foreach ($pager->getCurrentPageResults() as $user) {
            $items[] = $this->serializeUser($user);
        }

        return $this->json([
            'items' => $items,
            'total' => $pager->getNbResults(),
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @return array{
     *   id:int,
     *   name:string,
     *   surname:string,
     *   email:string,
     *   roles:string[],
     *   isVerified:bool,
     *   verifiedAt:?string
     * }
     */
    private function serializeUser(User $user): array
    {
        return [
            'id'         => $user->getId(),
            'name'       => $user->getName() ?? '',
            'surname'    => $user->getSurname() ?? '',
            'email'      => $user->getEmail() ?? '',
            'roles'      => $user->getRoles(),
            'isVerified' => $user->isVerified(),
            'verifiedAt' => $user->getVerifiedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }
}
