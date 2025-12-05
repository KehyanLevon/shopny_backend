<?php

namespace App\Controller;

use App\Dto\Product\ProductRequest;
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
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/products', name: 'api_products_')]
#[OA\Tag(name: 'Product')]
class ProductController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[OA\Get(
        description: 'Returns list of products with pagination, filters, search and sorting. Public endpoint (no auth required).',
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
                name: 'isActive',
                description: 'Filter by active flag (true/false, 1/0)',
                required: false,
                schema: new OA\Schema(type: 'boolean')
            ),
            new OA\QueryParameter(
                name: 'page',
                description: 'Page number (1-based)',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
            new OA\QueryParameter(
                name: 'limit',
                description: 'Items per page (1–100)',
                required: false,
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\QueryParameter(
                name: 'q',
                description: 'Search query (by title or description)',
                required: false,
                schema: new OA\Schema(type: 'string', maxLength: 255)
            ),
            new OA\QueryParameter(
                name: 'sortBy',
                description: 'Sort field: price, createdAt or title',
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
                                    new OA\Property(property: 'price', type: 'number', format: 'float'),
                                    new OA\Property(property: 'discountPrice', type: 'number', format: 'float', nullable: true),
                                    new OA\Property(property: 'status', type: 'string', example: 'active'),
                                    new OA\Property(property: 'isActive', type: 'boolean'),
                                    new OA\Property(
                                        property: 'images',
                                        description: 'Array of image paths like /uploads/products/xxx.png',
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
        $q = trim((string) $request->query->get('q', ''));
        if ($q !== '') {
            $q = mb_substr($q, 0, 255);
        }

        $sortBy  = (string) $request->query->get('sortBy', 'createdAt');
        $sortDir = strtolower((string) $request->query->get('sortDir', 'desc')) === 'asc' ? 'ASC' : 'DESC';

        $categoryIdInt = $categoryId !== null && $categoryId !== '' ? (int) $categoryId : null;
        $sectionIdInt  = $sectionId !== null && $sectionId !== '' ? (int) $sectionId : null;

        $isActive = null;
        if ($request->query->has('isActive')) {
            $isActive = filter_var(
                $request->query->get('isActive'),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );
        }

        $qb = $repo->createFilteredQuery(
            $categoryIdInt,
            $sectionIdInt,
            $isActive,
            $q !== '' ? $q : null,
            $sortBy,
            $sortDir
        );

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
                            description: 'Array of data URLs (data:image/*;base64,...)',
                            type: 'array',
                            items: new OA\Items(
                                type: 'string',
                                example: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...'
                            ),
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
            new OA\Response(response: 404, description: 'Product not found')
        ]
    )]
    public function show(Product $product): JsonResponse
    {
        return $this->json($this->serializeProduct($product));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        description: 'Admin only. Creates a new product. Images are sent as base64 data URLs and saved as files on server; DB stores only file paths.',
        summary: 'Create a new product',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['title', 'price', 'categoryId'],
                properties: [
                    new OA\Property(property: 'title', type: 'string'),
                    new OA\Property(property: 'price', type: 'number', format: 'float', example: 199.99),
                    new OA\Property(property: 'categoryId', type: 'integer'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'discountPrice', type: 'number', format: 'float', nullable: true),
                    new OA\Property(
                        property: 'status',
                        description: 'Product status enum: active, draft, out_of_stock',
                        type: 'string',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'images',
                        description: 'Array of data URLs (data:image/*;base64,...) — will be saved to /uploads/products and stored as paths.',
                        type: 'array',
                        items: new OA\Items(
                            type: 'string',
                            example: 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...'
                        ),
                        nullable: true
                    ),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                ],
                type: 'object'
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product created'),
            new OA\Response(response: 400, description: 'Invalid JSON'),
            new OA\Response(response: 404, description: 'Category not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepo,
        SluggerInterface $slugger,
        ValidatorInterface $validator,
        KernelInterface $kernel,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON'], 400);
        }

        $dto = new ProductRequest();
        $dto->setTitle($payload['title'] ?? null);
        $dto->setDescription($payload['description'] ?? null);
        $dto->setPrice($payload['price'] ?? null);
        $dto->setDiscountPrice($payload['discountPrice'] ?? null);
        $dto->setCategoryId($payload['categoryId'] ?? null);
        $dto->setStatus($payload['status'] ?? null);
        $dto->setIsActive($payload['isActive'] ?? null);

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }

        if ($dto->getDiscountPrice() !== null) {
            $priceFloat         = (float) $dto->getPrice();
            $discountPriceFloat = (float) $dto->getDiscountPrice();

            if ($discountPriceFloat >= $priceFloat) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'discountPrice' => ['Discount price must be lower than price.'],
                    ],
                ], 422);
            }
        }

        $errors = [];
        $imagesPaths = null;
        if (array_key_exists('images', $payload)) {
            $imagesPayload = $payload['images'];
            if ($imagesPayload === null) {
                $imagesPaths = null;
            } elseif (!is_array($imagesPayload)) {
                $errors['images'][] = 'images must be an array of strings.';
            } else {
                $dataUrls   = [];
                $plainPaths = [];
                foreach ($imagesPayload as $index => $img) {
                    if (!is_string($img)) {
                        $errors["images[$index]"][] = 'Each image must be a string.';
                        continue;
                    }
                    if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#', $img)) {
                        $dataUrls[] = $img;
                    } else {
                        $plainPaths[] = $img;
                    }
                }
                if (!empty($dataUrls)) {
                    $newPaths = $this->processImagesPayload(
                        $dataUrls,
                        $kernel->getProjectDir(),
                        $errors
                    );
                    if (!empty($errors)) {
                        return $this->json([
                            'message' => 'Validation failed.',
                            'errors'  => $errors,
                        ], 422);
                    }
                    $imagesPaths = array_merge($plainPaths, $newPaths ?? []);
                } else {
                    $imagesPaths = $plainPaths;
                }
            }
        }

        if (!empty($errors)) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $errors,
            ], 422);
        }


        $category = $categoryRepo->find($dto->getCategoryId());
        if (!$category) {
            return $this->json(['message' => 'Category not found'], 404);
        }

        $product = new Product();
        $product->setTitle($dto->getTitle());
        $product->setDescription($dto->getDescription());
        $product->setPrice($dto->getPrice());
        $product->setDiscountPrice($dto->getDiscountPrice());
        $product->setCategory($category);

        if ($dto->getStatus() !== null) {
            try {
                $product->setStatus(ProductStatus::from($dto->getStatus()));
            } catch (\ValueError) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'status' => ['Invalid status value. Allowed: active, draft, out_of_stock.'],
                    ],
                ], 422);
            }
        }

        if ($dto->getIsActive() !== null) {
            $product->setIsActive($dto->getIsActive());
        }

        if ($imagesPaths !== null) {
            $product->setImages($imagesPaths);
        }

        $em->persist($product);
        $em->flush();

        if ($product->getTitle() !== null && $product->getId() !== null) {
            $slug = (string) $slugger
                ->slug($product->getTitle() . '-' . $product->getId())
                ->lower();
            $product->setSlug($slug);

            $em->flush();
        }

        return $this->json($this->serializeProduct($product), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        description: 'Admin only. Partially update product fields by ID. If "images" is provided, new images will be processed as data URLs and old paths will be replaced.',
        summary: 'Update product',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'title', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', nullable: true),
                    new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'discountPrice', type: 'number', format: 'float', nullable: true),
                    new OA\Property(
                        property: 'status',
                        description: 'Product status enum: active, draft, out_of_stock',
                        type: 'string',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'images',
                        description: 'Array of data URLs (data:image/*;base64,...) or existing paths (/uploads/products/...). If omitted, images are unchanged.',
                        type: 'array',
                        items: new OA\Items(type: 'string'),
                        nullable: true
                    ),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                ],
                type: 'object'
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
            new OA\Response(response: 200, description: 'Product updated'),
            new OA\Response(response: 400, description: 'Invalid JSON'),
            new OA\Response(response: 404, description: 'Product or category not found'),
            new OA\Response(response: 422, description: 'Validation failed'),
        ]
    )]
    public function update(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        CategoryRepository $categoryRepo,
        SluggerInterface $slugger,
        ValidatorInterface $validator,
        KernelInterface $kernel,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON'], 400);
        }

        $dto = new ProductRequest();
        $dto->setTitle($payload['title'] ?? null);
        $dto->setDescription($payload['description'] ?? null);
        $dto->setPrice($payload['price'] ?? null);
        $dto->setDiscountPrice($payload['discountPrice'] ?? null);
        $dto->setCategoryId($payload['categoryId'] ?? null);
        $dto->setStatus($payload['status'] ?? null);
        $dto->setIsActive($payload['isActive'] ?? null);

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }

        if ($dto->getDiscountPrice() !== null) {
            $basePriceString = $dto->getPrice() ?? $product->getPrice();
            $basePriceFloat  = $basePriceString !== null ? (float) $basePriceString : 0.0;
            $discountFloat   = (float) $dto->getDiscountPrice();

            if ($discountFloat >= $basePriceFloat) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'discountPrice' => ['Discount price must be lower than price.'],
                    ],
                ], 422);
            }
        }

        $errors      = [];
        $imagesPaths = null;

        if (array_key_exists('images', $payload)) {
            $imagesPayload = $payload['images'];

            if ($imagesPayload === null) {
                $imagesPaths = null;
            } elseif (!is_array($imagesPayload)) {
                $errors['images'][] = 'images must be an array of strings.';
            } else {
                $dataUrls   = [];
                $plainPaths = [];

                foreach ($imagesPayload as $index => $img) {
                    if (!is_string($img)) {
                        $errors["images[$index]"][] = 'Each image must be a string.';
                        continue;
                    }

                    if (preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,#', $img)) {
                        $dataUrls[] = $img;
                    } else {
                        $plainPaths[] = $img;
                    }
                }

                if (!empty($errors)) {
                    return $this->json([
                        'message' => 'Validation failed.',
                        'errors'  => $errors,
                    ], 422);
                }

                if (!empty($dataUrls)) {
                    $processErrors = [];
                    $newPaths      = $this->processImagesPayload(
                        $dataUrls,
                        $kernel->getProjectDir(),
                        $processErrors
                    );

                    if (!empty($processErrors)) {
                        return $this->json([
                            'message' => 'Validation failed.',
                            'errors'  => $processErrors,
                        ], 422);
                    }

                    $imagesPaths = array_merge($plainPaths, $newPaths ?? []);
                } else {
                    $imagesPaths = $plainPaths;
                }
            }
        }

        if (!empty($errors)) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $errors,
            ], 422);
        }

        $titleChanged = false;

        if ($dto->getTitle() !== null) {
            $product->setTitle($dto->getTitle());
            $titleChanged = true;
        }

        if ($dto->getDescription() !== null) {
            $product->setDescription($dto->getDescription());
        }

        if ($dto->getPrice() !== null) {
            $product->setPrice($dto->getPrice());
        }

        if ($dto->getDiscountPrice() !== null) {
            $product->setDiscountPrice($dto->getDiscountPrice());
        }

        if ($dto->getCategoryId() !== null) {
            $category = $categoryRepo->find($dto->getCategoryId());
            if (!$category) {
                return $this->json(['message' => 'Category not found'], 404);
            }
            $product->setCategory($category);
        }

        if ($dto->getStatus() !== null) {
            try {
                $product->setStatus(ProductStatus::from($dto->getStatus()));
            } catch (\ValueError) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'status' => ['Invalid status value. Allowed: active, draft, out_of_stock.'],
                    ],
                ], 422);
            }
        }

        if ($dto->getIsActive() !== null) {
            $product->setIsActive($dto->getIsActive());
        }

            if (array_key_exists('images', $payload)) {
            $product->setImages($imagesPaths);
        }

        if ($titleChanged && $product->getTitle() !== null && $product->getId() !== null) {
            $slug = (string) $slugger
                ->slug($product->getTitle() . '-' . $product->getId())
                ->lower();
            $product->setSlug($slug);
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
            new OA\Response(response: 204, description: 'Deleted successfully'),
            new OA\Response(response: 404, description: 'Product not found'),
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

        $priceString         = $product->getPrice();
        $discountPriceString = $product->getDiscountPrice();

        return [
            'id'            => $product->getId(),
            'title'         => $product->getTitle(),
            'slug'          => $product->getSlug(),
            'description'   => $product->getDescription(),
            'price'         => $priceString !== null ? (float) $priceString : null,
            'discountPrice' => $discountPriceString !== null ? (float) $discountPriceString : null,
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

    /**
     * @param mixed  $imagesPayload
     * @param string $projectDir
     * @param array  $errors
     * @return array<string>|null
     */
    private function processImagesPayload(mixed $imagesPayload, string $projectDir, array &$errors): ?array
    {
        if ($imagesPayload === null) {
            return null;
        }

        if (!is_array($imagesPayload)) {
            $errors['images'][] = 'images must be an array of strings.';
            return null;
        }

        $decodedList = [];

        foreach ($imagesPayload as $index => $img) {
            if (!is_string($img)) {
                $errors["images[$index]"][] = 'Each image must be a string.';
                continue;
            }

            if (!preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.+)$#', $img, $m)) {
                $errors["images[$index]"][] = 'Each image must be a data:image/*;base64,... string.';
                continue;
            }

            $mimeExt = strtolower($m[1]);
            $base64  = $m[2];

            $binary = base64_decode($base64, true);
            if ($binary === false) {
                $errors["images[$index]"][] = 'Invalid base64 image data.';
                continue;
            }

            $ext = match ($mimeExt) {
                'jpeg' => 'jpg',
                default => $mimeExt,
            };

            $decodedList[] = [
                'ext'  => $ext,
                'data' => $binary,
            ];
        }

        if (!empty($errors)) {
            return null;
        }

        $targetDir = $projectDir . '/public/uploads/products';
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Cannot create uploads directory.');
        }

        $paths = [];

        foreach ($decodedList as $item) {
            $ext  = $item['ext'];
            $data = $item['data'];

            $filename = 'prod_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $fullPath = $targetDir . '/' . $filename;

            if (file_put_contents($fullPath, $data) === false) {
                throw new \RuntimeException('Failed to save uploaded image.');
            }

            $paths[] = '/uploads/products/' . $filename;
        }

        return $paths;
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
