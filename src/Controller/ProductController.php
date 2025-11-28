<?php

namespace App\Controller;

use App\Entity\Product;
use App\Enum\ProductStatus;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
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

#[Route('/api/products', name: 'api_products_')]
#[OA\Tag(name: 'Product')]
class ProductController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(
        description: 'Returns list of products with pagination, search and sorting. Public endpoint (no auth required).',
        summary: 'List products',
        parameters: [
            new OA\QueryParameter(
                name: 'categoryId',
                description: 'Filter by category ID',
                required: false,
                schema: new OA\Schema(type: 'integer')
            ),
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
                description: 'Search query (by title or description)',
                required: false,
                schema: new OA\Schema(type: 'string')
            ),
            new OA\QueryParameter(
                name: 'sortBy',
                description: 'Sort field: price or createdAt',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'createdAt')
            ),
            new OA\QueryParameter(
                name: 'sortDir',
                description: 'Sort direction: asc or desc',
                required: false,
                schema: new OA\Schema(type: 'string', default: 'desc')
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of products',
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
                                    new OA\Property(property: 'price', type: 'string', example: '199.99'),
                                    new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                                    new OA\Property(property: 'status', type: 'string', example: 'ACTIVE'),
                                    new OA\Property(property: 'isActive', type: 'boolean'),
                                    new OA\Property(
                                        property: 'images',
                                        type: 'array',
                                        items: new OA\Items(type: 'string'),
                                        nullable: true
                                    ),
                                    new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'categoryTitle', type: 'string', nullable: true),
                                    new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                                    new OA\Property(property: 'sectionTitle', type: 'string', nullable: true),
                                    new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                                    new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
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
        ProductRepository $repo,
        Request $request
    ): JsonResponse {
        $page  = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));

        $categoryId = $request->query->get('categoryId');
        $sectionId  = $request->query->get('sectionId');
        $q          = trim((string) $request->query->get('q', ''));

        $sortBy  = (string) $request->query->get('sortBy', 'createdAt');
        $sortDir = strtolower((string) $request->query->get('sortDir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $qb = $repo->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->leftJoin('c.section', 's')
            ->addSelect('c', 's');

        if ($categoryId !== null) {
            $qb->andWhere('c.id = :categoryId')->setParameter('categoryId', (int) $categoryId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('s.id = :sectionId')->setParameter('sectionId', (int) $sectionId);
        }

        if ($q !== '') {
            $qb
                ->andWhere('LOWER(p.title) LIKE :q OR LOWER(p.description) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($q) . '%');
        }

        if (!in_array($sortBy, ['price', 'createdAt', 'title'], true)) {
            $sortBy = 'createdAt';
        }

        $qb->orderBy('p.' . $sortBy, $sortDir);

        $qb->distinct();

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $items = array_map(
            fn(Product $p) => $this->serializeProduct($p),
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
        description: 'Returns single product by ID. Public endpoint (no auth required).',
        summary: 'Get product by ID',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Product ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Product found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'price', type: 'string', example: '199.99'),
                        new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string', example: 'ACTIVE'),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(
                            property: 'images',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            nullable: true
                        ),
                        new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                        new OA\Property(property: 'categoryTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'sectionTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Product not found'
            )
        ]
    )]
    public function show(Product $product): JsonResponse
    {
        return $this->json($this->serializeProduct($product));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        description: 'Admin only. Creates a new product.',
        summary: 'Create a new product',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'price', 'categoryId'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'price', type: 'string', example: '199.99'),
                    new OA\Property(property: 'categoryId', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'status',
                        description: 'Product status enum (e.g. ACTIVE, DRAFT, ARCHIVED)',
                        type: 'string',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'images',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        nullable: true
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Product created',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'price', type: 'string'),
                        new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(
                            property: 'images',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            nullable: true
                        ),
                        new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                        new OA\Property(property: 'categoryTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'sectionTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON or missing required fields',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepo,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }
        if (empty($payload['title']) || !isset($payload['price']) || empty($payload['categoryId'])) {
            return $this->json(['error' => 'title, price and categoryId are required'], 400);
        }
        $category = $categoryRepo->find((int)$payload['categoryId']);
        if (!$category) {
            return $this->json(['error' => 'Category not found'], 404);
        }

        $product = new Product();
        $product->setTitle($payload['title']);
        $product->setPrice($payload['price']);
        $product->setDescription($payload['description'] ?? null);
        $product->setDiscountPrice($payload['discountPrice'] ?? null);
        $product->setCategory($category);

        $slug = (string)$slugger->slug($payload['title'])->lower();
        $product->setSlug($slug);

        if (isset($payload['status'])) {
            $product->setStatus(ProductStatus::from($payload['status']));
        }
        if (isset($payload['images'])) {
            $product->setImages($payload['images']);
        }
        if (isset($payload['isActive'])) {
            $product->setIsActive((bool) $payload['isActive']);
        }

        $em->persist($product);
        $em->flush();

        return $this->json($this->serializeProduct($product), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        description: 'Admin only. Partially update product fields by ID.',
        summary: 'Update product',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'string', nullable: true),
                    new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'status',
                        description: 'Product status enum (e.g. ACTIVE, DRAFT, ARCHIVED)',
                        type: 'string',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'images',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        nullable: true
                    ),
                ]
            )
        ),
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Product ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Product updated',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'title', type: 'string'),
                        new OA\Property(property: 'slug', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(property: 'price', type: 'string'),
                        new OA\Property(property: 'discountPrice', type: 'string', nullable: true),
                        new OA\Property(property: 'status', type: 'string'),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(
                            property: 'images',
                            type: 'array',
                            items: new OA\Items(type: 'string'),
                            nullable: true
                        ),
                        new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                        new OA\Property(property: 'categoryTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                        new OA\Property(property: 'sectionTitle', type: 'string', nullable: true),
                        new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', nullable: true),
                        new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', nullable: true),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Invalid JSON',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'Category not found (if categoryId is invalid)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function update(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepo,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $titleChanged = false;

        if (isset($payload['title'])) {
            $product->setTitle($payload['title']);
            $titleChanged = true;
        }
        if (array_key_exists('description', $payload)) {
            $product->setDescription($payload['description']);
        }
        if (isset($payload['price'])) {
            $product->setPrice($payload['price']);
        }
        if (array_key_exists('discountPrice', $payload)) {
            $raw = $payload['discountPrice'];
            if ($raw === null || $raw === '') {
                $product->setDiscountPrice(null);
            } else {
                $product->setDiscountPrice((string) $raw);
            }
        }
        if (isset($payload['categoryId'])) {
            $category = $categoryRepo->find((int)$payload['categoryId']);
            if (!$category) {
                return $this->json(['error' => 'Category not found'], 404);
            }
            $product->setCategory($category);
        }

        if (isset($payload['status'])) {
            $product->setStatus(ProductStatus::from($payload['status']));
        }
        if (isset($payload['images'])) {
            $product->setImages($payload['images']);
        }
        if ($titleChanged) {
            $product->setSlug((string)$slugger->slug($product->getTitle())->lower());
        }
        if (isset($payload['isActive'])) {
            $product->setIsActive((bool) $payload['isActive']);
        }

        $product->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json($this->serializeProduct($product));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        description: 'Admin only. Deletes product by ID.',
        summary: 'Delete product',
        parameters: [
            new OA\PathParameter(
                name: 'id',
                description: 'Product ID',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 204,
                description: 'Product deleted (no content)'
            )
        ]
    )]
    public function delete(
        Product $product,
        EntityManagerInterface $em
    ): JsonResponse {
        $em->remove($product);
        $em->flush();

        return $this->json(null, 204);
    }

    private function serializeProduct(Product $product): array
    {
        $category = $product->getCategory();
        $section  = $category?->getSection();

        return [
            'id'            => $product->getId(),
            'title'         => $product->getTitle(),
            'slug'          => $product->getSlug(),
            'description'   => $product->getDescription(),
            'price'         => $product->getPrice(),
            'discountPrice' => $product->getDiscountPrice(),
            'status'        => $product->getStatus()->value,
            'isActive'      => $product->isActive(),
            'images'        => $product->getImages(),
            'categoryId'    => $category?->getId(),
            'categoryTitle' => $category?->getTitle(),
            'sectionId'     => $section?->getId(),
            'sectionTitle'  => $section?->getTitle(),
            'createdAt'     => $product->getCreatedAt()?->format(DATE_ATOM),
            'updatedAt'     => $product->getUpdatedAt()?->format(DATE_ATOM),
        ];
    }
}
