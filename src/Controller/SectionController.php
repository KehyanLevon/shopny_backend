<?php

namespace App\Controller;

use App\Entity\Section;
use App\Repository\SectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use App\Dto\Section\SectionRequest;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

#[Route('/api/sections', name: 'api_sections_')]
#[OA\Tag(name: 'Section')]
class SectionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(
        description: 'Returns all sections with pagination & search.',
        summary: 'List all sections',
        parameters: [
            new OA\QueryParameter(
                name: 'page',
                description: 'Page number (1-based)',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
            new OA\QueryParameter(
                name: 'limit',
                description: 'Items per page',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\QueryParameter(
                name: 'q',
                description: 'Search query (by title or slug)',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of sections',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'title', type: 'string'),
                                    new OA\Property(property: 'slug', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(property: 'isActive', type: 'boolean'),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'categoriesCount', type: 'integer'),
                                ],
                                type: 'object'
                            )
                        ),
                        new OA\Property(property: 'total', type: 'integer'),
                        new OA\Property(property: 'page', type: 'integer'),
                        new OA\Property(property: 'limit', type: 'integer'),
                        new OA\Property(property: 'pages', type: 'integer'),
                    ],
                    type: 'object'
                )
            )
        ]
    )]
    public function index(SectionRepository $sectionRepository, Request $request): JsonResponse
    {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $q     = trim((string) $request->query->get('q', ''));

        $qb = $sectionRepository->createQueryBuilder('s');

        if ($q !== '') {
            $qb
                ->andWhere('LOWER(s.title) LIKE :q OR LOWER(s.slug) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $qb->orderBy('s.id', 'ASC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $items = array_map(
            fn (Section $section) => $this->serializeSection($section),
            iterator_to_array($pager->getCurrentPageResults())
        );

        return $this->json([
            'items' => $items,
            'total' => $pager->getNbResults(),
            'page'  => $page,
            'limit' => $limit,
            'pages' => $pager->getNbPages(),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[OA\Get(
        description: 'Returns single section by id.',
        summary: 'Get section by ID',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Section ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Section found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'categoriesCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Section not found'
            )
        ]
    )]
    public function show(Section $section): JsonResponse
    {
        return $this->json($this->serializeSection($section));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        description: 'Admin only. Creates a new section.',
        summary: 'Create a new section',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Section created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'categoriesCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string')
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                        ),
                    ]
                )
            )
        ]
    )]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json([
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $dto = new SectionRequest();
        $dto->setTitle($data['title'] ?? null);
        $dto->setDescription($data['description'] ?? null);

        $dto->setIsActive(
            array_key_exists('isActive', $data)
                ? $data['isActive']
                : true
        );
        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }
        $section = new Section();
        $section->setTitle($dto->getTitle());
        $section->setDescription($dto->getDescription());
        $section->setCreatedAt(new \DateTimeImmutable());
        $section->setIsActive($dto->getIsActive() ?? true);
        $em->persist($section);
        $em->flush();
        $slug = (string) $slugger
            ->slug($section->getTitle() . '-' . $section->getId())
            ->lower();
        $section->setSlug($slug);
        $em->flush();
        return $this->json($this->serializeSection($section), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        description: 'Admin only. Updates section fields by ID.',
        summary: 'Update section',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                ]
            )
        ),
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Section ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Section updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'categoriesCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string')
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(
                            property: 'errors',
                            type: 'object',
                        ),
                    ]
                )
            )
        ]
    )]
    public function update(
        Section $section,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ValidatorInterface $validator
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $dto = new SectionRequest();
        $dto->setTitle($data['title'] ?? null);
        $dto->setDescription($data['description'] ?? null);
        $dto->setIsActive(
            array_key_exists('isActive', $data)
                ? $data['isActive']
                : true
        );

        $violations = $validator->validate($dto);

        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }

        $titleChanged = false;

        if ($dto->getTitle() !== null) {
            $section->setTitle($dto->getTitle());
            $titleChanged = true;
        }

        if ($dto->getDescription() !== null) {
            $section->setDescription($dto->getDescription());
        }

        if ($dto->getIsActive() !== null) {
            $section->setIsActive($dto->getIsActive());
        }

        if ($titleChanged && $section->getTitle() !== null) {
            $newSlug = (string) $slugger
                ->slug($section->getTitle())
                ->lower();
            $section->setSlug($newSlug);
        }

        $section->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json($this->serializeSection($section));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        description: 'Admin only. Deletes section by ID.',
        summary: 'Delete section',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Section ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Section deleted (no content)'
            )
        ]
    )]
    public function delete(
        Section $section,
        EntityManagerInterface $em
    ): JsonResponse {
        $em->remove($section);
        $em->flush();

        return $this->json(null, 204);
    }

    private function serializeSection(Section $section): array
    {
        return [
            'id'              => $section->getId(),
            'title'           => $section->getTitle(),
            'slug'            => $section->getSlug(),
            'description'     => $section->getDescription(),
            'isActive'        => $section->getIsActive(),
            'createdAt'       => $section->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt'       => $section->getUpdatedAt()?->format(DATE_ATOM),
            'categoriesCount' => $section->getCategories()->count(),
        ];
    }

    private function formatValidationErrors(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $field = $violation->getPropertyPath() ?: 'global';
            $errors[$field][] = $violation->getMessage();
        }
        return $errors;
    }
}
