<?php

namespace App\Controller;

use App\Dto\Category\CategoryRequest;
use App\Entity\Category;
use App\Repository\CategoryRepository;
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
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/categories', name: 'api_categories_')]
#[OA\Tag(name: 'Category')]
class CategoryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(
        description: 'Returns list of categories with pagination & search. Public endpoint (no auth required).',
        summary: 'List categories',
        parameters: [
            new OA\QueryParameter(
                name: 'sectionId',
                description: 'Filter by section ID',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
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
                description: 'Paginated list of categories',
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
                                    new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'productsCount', type: 'integer'),
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
    public function index(
        CategoryRepository $categoryRepository,
        Request $request,
    ): JsonResponse {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $q     = trim((string) $request->query->get('q', ''));
        $sectionId = $request->query->get('sectionId');

        $qb = $categoryRepository->createQueryBuilder('c')
            ->leftJoin('c.section', 's')
            ->addSelect('s');

        if ($sectionId !== null) {
            $qb
                ->andWhere('s.id = :sectionId')
                ->setParameter('sectionId', (int) $sectionId);
        }

        if ($q !== '') {
            $qb
                ->andWhere('LOWER(c.title) LIKE :q OR LOWER(c.slug) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        $qb->orderBy('c.id', 'ASC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $items = array_map(
            fn (Category $category) => $this->serializeCategory($category),
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
        description: 'Returns single category by ID. Public endpoint (no auth required).',
        summary: 'Get category by ID',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Category ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'productsCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found'
            )
        ]
    )]
    public function show(Category $category): JsonResponse
    {
        return $this->json($this->serializeCategory($category));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        description: 'Admin only. Creates a new category.',
        summary: 'Create a new category',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'sectionId'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'sectionId', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Category created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'productsCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Section not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                        new OA\Property(property: 'errors', type: 'object'),
                    ]
                )
            ),
        ]
    )]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger,
        ValidatorInterface $validator,
        SectionRepository $sectionRepository,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON'], 400);
        }

        $dto = new CategoryRequest();
        $dto->setTitle($payload['title'] ?? null);
        $dto->setDescription($payload['description'] ?? null);
        $dto->setSectionId($payload['sectionId'] ?? null);
        $dto->setIsActive($payload['isActive'] ?? null);

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors' => $this->formatValidationErrors($violations),
            ], 422);
        }

        $section = $sectionRepository->find($dto->getSectionId());
        if (!$section) {
            return $this->json(['message' => 'Section not found'], 404);
        }

        $category = new Category();
        $category->setTitle($dto->getTitle());
        $category->setDescription($dto->getDescription());
        $category->setSection($section);
        $category->setIsActive($dto->getIsActive());

        $em->persist($category);
        $em->flush();

        if ($category->getId() !== null && $category->getTitle()) {
            $slug = (string) $slugger
                ->slug($category->getTitle() . '-' . $category->getId())
                ->lower();

            $category->setSlug($slug);
            $em->flush();
        }

        return $this->json($this->serializeCategory($category), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        description: 'Admin only. Partially update category by ID.',
        summary: 'Update category',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                ],
                type: 'object'
            )
        ),
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Category ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Category updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string', nullable: true),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'productsCount', type: 'integer'),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Section not found',
                content: new OA\JsonContent(
                    type: 'object',
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function update(
        Category $category,
        Request $request,
        EntityManagerInterface $em,
        SectionRepository $sectionRepository,
        SluggerInterface $slugger,
        ValidatorInterface $validator,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON'], 400);
        }

        $dto = new CategoryRequest();

        if (\array_key_exists('title', $payload)) {
            $dto->setTitle($payload['title']);
        }
        if (\array_key_exists('description', $payload)) {
            $dto->setDescription($payload['description']);
        }
        if (\array_key_exists('sectionId', $payload)) {
            $dto->setSectionId($payload['sectionId']);
        }
        if (\array_key_exists('isActive', $payload)) {
            $dto->setIsActive($payload['isActive']);
        }
        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }
        $titleChanged = false;

        if (\array_key_exists('title', $payload) && $dto->getTitle() !== null) {
            $category->setTitle($dto->getTitle());
            $titleChanged = true;
        }
        if (\array_key_exists('description', $payload)) {
            $category->setDescription($dto->getDescription());
        }
        if (\array_key_exists('sectionId', $payload)) {
            $rawSectionId = $payload['sectionId'] ?? null;

            if ($rawSectionId === null || $rawSectionId === '') {
                $category->setSection(null);
            } else {
                $sectionId = $dto->getSectionId();
                if ($sectionId === null) {
                    return $this->json([
                        'message' => 'Invalid sectionId value.',
                    ], 422);
                }
                $section = $sectionRepository->find($sectionId);
                if (!$section) {
                    return $this->json(['message' => 'Section not found'], 404);
                }
                $category->setSection($section);
            }
        }
        if (\array_key_exists('isActive', $payload)) {
            $category->setIsActive($dto->getIsActive() ?? false);
        }
        if (
            $titleChanged
            && $category->getId() !== null
            && $category->getTitle() !== null
        ) {
            $slug = (string) $slugger
                ->slug($category->getTitle() . '-' . $category->getId())
                ->lower();
            $category->setSlug($slug);
        }
        $category->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json($this->serializeCategory($category));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        description: 'Admin only. Deletes category by ID.',
        summary: 'Delete category',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Category ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Category deleted (no content)'
            )
        ]
    )]
    public function delete(
        Category $category,
        EntityManagerInterface $em,
    ): JsonResponse {
        $em->remove($category);
        $em->flush();

        return $this->json(null, 204);
    }

    private function serializeCategory(Category $category): array
    {
        $section = $category->getSection();

        return [
            'id'            => $category->getId(),
            'title'         => $category->getTitle(),
            'slug'          => $category->getSlug(),
            'description'   => $category->getDescription(),
            'isActive'      => $category->isActive(),
            'sectionId'     => $section?->getId(),
            'createdAt'     => $category->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt'     => $category->getUpdatedAt()?->format(DATE_ATOM),
            'productsCount' => $category->getProducts()->count(),
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
