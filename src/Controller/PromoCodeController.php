<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\PromoCode;
use App\Entity\Section;
use App\Enum\PromoScopeType;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\PromoCodeRepository;
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
use App\Dto\PromoCode\PromoCodeRequest;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

#[Route('/api/promocodes', name: 'api_promocodes_')]
class PromoCodeController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        summary: 'List promo codes with pagination and filters',
        tags: ['PromoCodes'],
        parameters: [
            new OA\Parameter(
                name: 'page',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 1)
            ),
            new OA\Parameter(
                name: 'limit',
                in: 'query',
                schema: new OA\Schema(type: 'integer', default: 20)
            ),
            new OA\Parameter(
                name: 'search',
                description: 'Search by code or description',
                in: 'query',
                schema: new OA\Schema(type: 'string')
            ),
            new OA\Parameter(
                name: 'scopeType',
                description: 'Filter by scope type: all, section, category, product',
                in: 'query',
                schema: new OA\Schema(type: 'string', enum: ['all', 'section', 'category', 'product'])
            ),
            new OA\Parameter(
                name: 'isActive',
                description: 'Filter by active flag (0 or 1)',
                in: 'query',
                schema: new OA\Schema(type: 'integer', enum: [0, 1])
            ),
            new OA\Parameter(
                name: 'isExpired',
                description: 'Filter by expiration: 1 = only expired, 0 = only not expired',
                in: 'query',
                schema: new OA\Schema(type: 'integer', enum: [0, 1])
            ),
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Paginated list of promo codes',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'items',
                            type: 'array',
                            items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'id', type: 'integer'),
                                    new OA\Property(property: 'code', type: 'string'),
                                    new OA\Property(property: 'description', type: 'string', nullable: true),
                                    new OA\Property(
                                        property: 'scopeType',
                                        type: 'string',
                                        enum: ['all', 'section', 'category', 'product']
                                    ),
                                    new OA\Property(property: 'discountPercent', type: 'string'),
                                    new OA\Property(property: 'isActive', type: 'boolean'),
                                    new OA\Property(
                                        property: 'startsAt',
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true
                                    ),
                                    new OA\Property(
                                        property: 'expiresAt',
                                        type: 'string',
                                        format: 'date-time',
                                        nullable: true
                                    ),
                                    new OA\Property(
                                        property: 'section',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer'),
                                            new OA\Property(property: 'title', type: 'string'),
                                        ],
                                        type: 'object',
                                        nullable: true
                                    ),
                                    new OA\Property(
                                        property: 'category',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer'),
                                            new OA\Property(property: 'title', type: 'string'),
                                        ],
                                        type: 'object',
                                        nullable: true
                                    ),
                                    new OA\Property(
                                        property: 'product',
                                        properties: [
                                            new OA\Property(property: 'id', type: 'integer'),
                                            new OA\Property(property: 'title', type: 'string'),
                                        ],
                                        type: 'object',
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
    public function index(Request $request, PromoCodeRepository $repo): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
        $search = trim((string) $request->query->get('search', ''));
        $scopeType = $request->query->get('scopeType');
        $isActive = $request->query->get('isActive');
        $isExpired = $request->query->get('isExpired');

        $qb = $repo->createQueryBuilder('p');

        if ($search !== '') {
            $term = '%' . mb_strtolower($search) . '%';
            $qb
                ->andWhere('LOWER(p.code) LIKE :term OR LOWER(p.description) LIKE :term')
                ->setParameter('term', $term);
        }

        if (is_string($scopeType) && $scopeType !== '') {
            if (in_array($scopeType, array_column(PromoScopeType::cases(), 'value'), true)) {
                $qb
                    ->andWhere('p.scopeType = :scopeType')
                    ->setParameter('scopeType', $scopeType);
            }
        }

        if ($isActive !== null && $isActive !== '') {
            $qb
                ->andWhere('p.isActive = :isActive')
                ->setParameter('isActive', (bool) (int) $isActive);
        }

        $now = new \DateTimeImmutable();

        if ($isExpired !== null && $isExpired !== '') {
            $flag = (int) $isExpired;

            if ($flag === 1) {
                $qb
                    ->andWhere('p.expiresAt IS NOT NULL AND p.expiresAt < :now')
                    ->setParameter('now', $now);
            } elseif ($flag === 0) {
                $qb
                    ->andWhere('p.expiresAt IS NULL OR p.expiresAt >= :now')
                    ->setParameter('now', $now);
            }
        }

        $qb->orderBy('p.createdAt', 'DESC');

        $pager = new Pagerfanta(new QueryAdapter($qb));
        $pager->setMaxPerPage($limit);
        $pager->setCurrentPage($page);

        $items = [];
        /** @var PromoCode $promo */
        foreach ($pager->getCurrentPageResults() as $promo) {
            $items[] = $this->serializePromo($promo);
        }

        return $this->json([
            'items' => $items,
            'total' => $pager->getNbResults(),
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Get(
        summary: 'Get single promo code by ID',
        tags: ['PromoCodes'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Promo code',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'code', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(
                            property: 'scopeType',
                            type: 'string',
                            enum: ['all', 'section', 'category', 'product']
                        ),
                        new OA\Property(property: 'discountPercent', type: 'string'),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(
                            property: 'startsAt',
                            type: 'string',
                            format: 'date-time',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'expiresAt',
                            type: 'string',
                            format: 'date-time',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'section',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'category',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'product',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 404, description: 'Promo code not found')
        ]
    )]
    public function show(PromoCode $promo): JsonResponse
    {
        return $this->json($this->serializePromo($promo));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Post(
        summary: 'Create a new promo code',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'discountPercent', 'scopeType'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'WELCOME10'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'scopeType',
                        type: 'string',
                        enum: ['all', 'section', 'category', 'product']
                    ),
                    new OA\Property(
                        property: 'discountPercent',
                        description: 'Percent discount between 0 and 100',
                        type: 'number',
                        format: 'float',
                        example: 10.0
                    ),
                    new OA\Property(
                        property: 'sectionId',
                        description: 'Required if scopeType=section',
                        type: 'integer',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'categoryId',
                        description: 'Required if scopeType=category',
                        type: 'integer',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'productId',
                        description: 'Required if scopeType=product',
                        type: 'integer',
                        nullable: true
                    ),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                    new OA\Property(
                        property: 'startsAt',
                        type: 'string',
                        format: 'date-time',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'expiresAt',
                        type: 'string',
                        format: 'date-time',
                        nullable: true
                    ),
                ],
                type: 'object'
            )
        ),
        tags: ['PromoCodes'],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created promo code'
            ),
            new OA\Response(
                response: 422,
                description: 'Validation failed'
            )
        ]
    )]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SectionRepository $sectionRepo,
        CategoryRepository $categoryRepo,
        ProductRepository $productRepo,
        ValidatorInterface $validator,
        PromoCodeRepository $promoRepo
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body.'], 400);
        }

        $dto = new PromoCodeRequest();
        $dto->setCode($payload['code'] ?? null);
        $dto->setDescription($payload['description'] ?? null);
        $dto->setScopeType($payload['scopeType'] ?? null);
        $dto->setDiscountPercent($payload['discountPercent'] ?? null);
        $dto->setIsActive($payload['isActive'] ?? null);
        $dto->setStartsAt($payload['startsAt'] ?? null);
        $dto->setExpiresAt($payload['expiresAt'] ?? null);
        $dto->setSectionId(isset($payload['sectionId']) ? (int) $payload['sectionId'] : null);
        $dto->setCategoryId(isset($payload['categoryId']) ? (int) $payload['categoryId'] : null);
        $dto->setProductId(isset($payload['productId']) ? (int) $payload['productId'] : null);

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }

        // --- проверка уникальности code (case-insensitive) ---
        $code = trim((string) $dto->getCode());
        if ($code !== '') {
            $existing = $promoRepo->createQueryBuilder('p')
                ->andWhere('LOWER(p.code) = :code')
                ->setParameter('code', mb_strtolower($code))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing instanceof PromoCode) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'code' => ['Promo code with this code already exists.'],
                    ],
                ], 422);
            }
        }
        // ----------------------------------------------------

        $scopeType = $this->parseScopeType($dto->getScopeType());
        if ($scopeType === null) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => ['scopeType' => ['Invalid scopeType.']],
            ], 422);
        }

        $promo = new PromoCode();
        $promo->setCode($code); // уже нормализованный
        $promo->setDescription($dto->getDescription());
        $promo->setScopeType($scopeType);

        $discountPercent = (float) $dto->getDiscountPercent();
        $promo->setDiscountPercent(number_format($discountPercent, 2, '.', ''));

        $scopePayload = [
            'sectionId'  => $dto->getSectionId(),
            'categoryId' => $dto->getCategoryId(),
            'productId'  => $dto->getProductId(),
        ];

        try {
            $this->applyScopeRelations(
                $promo,
                $scopeType,
                $scopePayload,
                $sectionRepo,
                $categoryRepo,
                $productRepo
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => ['scope' => [$e->getMessage()]],
            ], 422);
        }

        if ($dto->getIsActive() !== null) {
            $promo->setIsActive($dto->getIsActive());
        }

        if ($dto->getStartsAt() !== null) {
            $promo->setStartsAt(new \DateTimeImmutable($dto->getStartsAt()));
        }

        if ($dto->getExpiresAt() !== null) {
            $promo->setExpiresAt(new \DateTimeImmutable($dto->getExpiresAt()));
        }

        $em->persist($promo);
        $em->flush();

        return $this->json($this->serializePromo($promo), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Patch(
        summary: 'Update an existing promo code',
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(
                        property: 'scopeType',
                        type: 'string',
                        enum: ['all', 'section', 'category', 'product'],
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'discountPercent',
                        description: 'Percent discount between 0 and 100',
                        type: 'number',
                        format: 'float',
                        nullable: true
                    ),
                    new OA\Property(property: 'sectionId', type: 'integer', nullable: true),
                    new OA\Property(property: 'categoryId', type: 'integer', nullable: true),
                    new OA\Property(property: 'productId', type: 'integer', nullable: true),
                    new OA\Property(property: 'isActive', type: 'boolean', nullable: true),
                    new OA\Property(
                        property: 'startsAt',
                        type: 'string',
                        format: 'date-time',
                        nullable: true
                    ),
                    new OA\Property(
                        property: 'expiresAt',
                        type: 'string',
                        format: 'date-time',
                        nullable: true
                    ),
                ],
                type: 'object'
            )
        ),
        tags: ['PromoCodes'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Updated promo code',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'code', type: 'string'),
                        new OA\Property(property: 'description', type: 'string', nullable: true),
                        new OA\Property(
                            property: 'scopeType',
                            type: 'string',
                            enum: ['all', 'section', 'category', 'product']
                        ),
                        new OA\Property(property: 'discountPercent', type: 'string'),
                        new OA\Property(property: 'isActive', type: 'boolean'),
                        new OA\Property(
                            property: 'startsAt',
                            type: 'string',
                            format: 'date-time',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'expiresAt',
                            type: 'string',
                            format: 'date-time',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'section',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'category',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                        new OA\Property(
                            property: 'product',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer'),
                                new OA\Property(property: 'title', type: 'string'),
                            ],
                            type: 'object',
                            nullable: true
                        ),
                    ],
                    type: 'object'
                )
            ),
            new OA\Response(response: 400, description: 'Validation error'),
            new OA\Response(response: 404, description: 'Promo code not found')
        ]
    )]
    public function update(
        PromoCode $promo,
        Request $request,
        EntityManagerInterface $em,
        SectionRepository $sectionRepo,
        CategoryRepository $categoryRepo,
        ProductRepository $productRepo,
        ValidatorInterface $validator,
        PromoCodeRepository $promoRepo
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['message' => 'Invalid JSON body.'], 400);
        }

        $dto = new PromoCodeRequest();
        $dto->setCode($payload['code'] ?? $promo->getCode());

        if (array_key_exists('description', $payload)) {
            $dto->setDescription($payload['description']);
        } else {
            $dto->setDescription($promo->getDescription());
        }

        $scopeTypeString = $payload['scopeType'] ?? $promo->getScopeType()->value;
        $dto->setScopeType($scopeTypeString);

        if (array_key_exists('discountPercent', $payload)) {
            $dto->setDiscountPercent($payload['discountPercent']);
        } else {
            $dto->setDiscountPercent($promo->getDiscountPercent());
        }

        if (array_key_exists('isActive', $payload)) {
            $dto->setIsActive($payload['isActive']);
        } else {
            $dto->setIsActive($promo->isActive());
        }

        if (array_key_exists('startsAt', $payload)) {
            if ($payload['startsAt'] === null || $payload['startsAt'] === '') {
                $dto->setStartsAt(null);
            } else {
                $dto->setStartsAt($payload['startsAt']);
            }
        } else {
            $dto->setStartsAt(
                $promo->getStartsAt()?->format(\DateTimeInterface::ATOM)
            );
        }

        if (array_key_exists('expiresAt', $payload)) {
            if ($payload['expiresAt'] === null || $payload['expiresAt'] === '') {
                $dto->setExpiresAt(null);
            } else {
                $dto->setExpiresAt($payload['expiresAt']);
            }
        } else {
            $dto->setExpiresAt(
                $promo->getExpiresAt()?->format(\DateTimeInterface::ATOM)
            );
        }

        if (array_key_exists('sectionId', $payload)) {
            $dto->setSectionId(
                $payload['sectionId'] !== null ? (int) $payload['sectionId'] : null
            );
        } else {
            $dto->setSectionId($promo->getSection()?->getId());
        }

        if (array_key_exists('categoryId', $payload)) {
            $dto->setCategoryId(
                $payload['categoryId'] !== null ? (int) $payload['categoryId'] : null
            );
        } else {
            $dto->setCategoryId($promo->getCategory()?->getId());
        }

        if (array_key_exists('productId', $payload)) {
            $dto->setProductId(
                $payload['productId'] !== null ? (int) $payload['productId'] : null
            );
        } else {
            $dto->setProductId($promo->getProduct()?->getId());
        }

        $violations = $validator->validate($dto);
        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }
        $code = trim((string) $dto->getCode());
        if ($code !== '') {
            $existing = $promoRepo->createQueryBuilder('p')
                ->andWhere('LOWER(p.code) = :code')
                ->setParameter('code', mb_strtolower($code))
                ->andWhere('p.id != :id')
                ->setParameter('id', $promo->getId())
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($existing instanceof PromoCode) {
                return $this->json([
                    'message' => 'Validation failed.',
                    'errors'  => [
                        'code' => ['Promo code with this code already exists.'],
                    ],
                ], 422);
            }
        }
        if (isset($payload['code'])) {
            $promo->setCode($code);
        }
        if (array_key_exists('description', $payload)) {
            $promo->setDescription($payload['description'] ?? null);
        }
        $newScopeType = $this->parseScopeType($dto->getScopeType());
        if ($newScopeType === null) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => ['scopeType' => ['Invalid scopeType.']],
            ], 422);
        }
        if (isset($payload['scopeType'])) {
            $promo->setScopeType($newScopeType);
            $promo->setSection(null);
            $promo->setCategory(null);
            $promo->setProduct(null);
        }
        $scopePayload = [
            'sectionId'  => $dto->getSectionId(),
            'categoryId' => $dto->getCategoryId(),
            'productId'  => $dto->getProductId(),
        ];
        try {
            $this->applyScopeRelations(
                $promo,
                $promo->getScopeType(),
                $scopePayload,
                $sectionRepo,
                $categoryRepo,
                $productRepo
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => ['scope' => [$e->getMessage()]],
            ], 422);
        }
        if (isset($payload['discountPercent'])) {
            $discountPercent = (float) $dto->getDiscountPercent();
            $promo->setDiscountPercent(number_format($discountPercent, 2, '.', ''));
        }
        if (isset($payload['isActive'])) {
            $promo->setIsActive((bool) $payload['isActive']);
        }
        if (array_key_exists('startsAt', $payload)) {
            if ($dto->getStartsAt() === null) {
                $promo->setStartsAt(null);
            } else {
                $promo->setStartsAt(new \DateTimeImmutable($dto->getStartsAt()));
            }
        }
        if (array_key_exists('expiresAt', $payload)) {
            if ($dto->getExpiresAt() === null) {
                $promo->setExpiresAt(null);
            } else {
                $promo->setExpiresAt(new \DateTimeImmutable($dto->getExpiresAt()));
            }
        }
        $promo->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        return $this->json($this->serializePromo($promo));
    }


    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    #[OA\Delete(
        summary: 'Delete promo code',
        tags: ['PromoCodes'],
        parameters: [
            new OA\Parameter(
                name: 'id',
                in: 'path',
                required: true,
                schema: new OA\Schema(type: 'integer')
            )
        ],
        responses: [
            new OA\Response(response: 204, description: 'Promo code deleted'),
            new OA\Response(response: 404, description: 'Promo code not found')
        ]
    )]
    public function delete(PromoCode $promo, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($promo);
        $em->flush();

        return $this->json(null, 204);
    }

    #[Route('/verify', name: 'verify', methods: ['POST'])]
    #[OA\Post(
        summary: 'Verify promo code validity',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code'],
                properties: [
                    new OA\Property(property: 'code', type: 'string'),
                ]
            )
        ),
        tags: ['PromoCodes'],
        responses: [
            new OA\Response(response: 200, description: 'Verification result')
        ]
    )]
    public function verify(
        Request $request,
        PromoCodeRepository $repo
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || empty($payload['code'])) {
            return $this->json(['valid' => false, 'reason' => 'code_required'], 200);
        }

        $code = trim((string) $payload['code']);

        /** @var PromoCode|null $promo */
        $promo = $repo->createQueryBuilder('p')
            ->andWhere('LOWER(p.code) = :code')
            ->setParameter('code', mb_strtolower($code))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$promo) {
            return $this->json(['valid' => false, 'reason' => 'not_found'], 200);
        }

        $now = new \DateTimeImmutable();

        if (!$promo->isActive()) {
            return $this->json([
                'valid'  => false,
                'reason' => 'inactive',
                'promo'  => $this->serializePromo($promo),
            ], 200);
        }

        if ($promo->getStartsAt() && $now < $promo->getStartsAt()) {
            return $this->json([
                'valid'  => false,
                'reason' => 'not_started',
                'promo'  => $this->serializePromo($promo),
            ], 200);
        }

        if ($promo->getExpiresAt() && $now > $promo->getExpiresAt()) {
            return $this->json([
                'valid'  => false,
                'reason' => 'expired',
                'promo'  => $this->serializePromo($promo),
            ], 200);
        }

        return $this->json([
            'valid' => true,
            'promo' => $this->serializePromo($promo),
        ], 200);
    }

    private function parseScopeType(mixed $value): ?PromoScopeType
    {
        if (!is_string($value)) {
            return null;
        }

        foreach (PromoScopeType::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function applyScopeRelations(
        PromoCode $promo,
        PromoScopeType $scopeType,
        array $payload,
        SectionRepository $sectionRepo,
        CategoryRepository $categoryRepo,
        ProductRepository $productRepo
    ): void {
        if ($scopeType === PromoScopeType::ALL) {
            $promo->setSection(null);
            $promo->setCategory(null);
            $promo->setProduct(null);
            return;
        }

        if ($scopeType === PromoScopeType::SECTION) {
            if (empty($payload['sectionId'])) {
                throw new \InvalidArgumentException('sectionId is required for scopeType=section');
            }
            $section = $sectionRepo->find((int) $payload['sectionId']);
            if (!$section instanceof Section) {
                throw new \InvalidArgumentException('Section not found');
            }
            $promo->setSection($section);
            $promo->setCategory(null);
            $promo->setProduct(null);
        }

        if ($scopeType === PromoScopeType::CATEGORY) {
            if (empty($payload['categoryId'])) {
                throw new \InvalidArgumentException('categoryId is required for scopeType=category');
            }
            $category = $categoryRepo->find((int) $payload['categoryId']);
            if (!$category instanceof Category) {
                throw new \InvalidArgumentException('Category not found');
            }
            $promo->setCategory($category);
            $promo->setSection(null);
            $promo->setProduct(null);
        }

        if ($scopeType === PromoScopeType::PRODUCT) {
            if (empty($payload['productId'])) {
                throw new \InvalidArgumentException('productId is required for scopeType=product');
            }
            $product = $productRepo->find((int) $payload['productId']);
            if (!$product instanceof Product) {
                throw new \InvalidArgumentException('Product not found');
            }
            $promo->setProduct($product);
            $promo->setCategory(null);
            $promo->setSection(null);
        }
    }

    /**
     * @return array{
     *   id:int,
     *   code:string,
     *   description:?string,
     *   scopeType:string,
     *   discountPercent:string,
     *   isActive:bool,
     *   startsAt:?string,
     *   expiresAt:?string,
     *   section:?array,
     *   category:?array,
     *   product:?array
     * }
     */
    private function serializePromo(PromoCode $promo): array
    {
        $section = $promo->getSection();
        $category = $promo->getCategory();
        $product = $promo->getProduct();

        return [
            'id'              => $promo->getId(),
            'code'            => $promo->getCode(),
            'description'     => $promo->getDescription(),
            'scopeType'       => $promo->getScopeType()->value,
            'discountPercent' => $promo->getDiscountPercent(),
            'isActive'        => $promo->isActive(),
            'startsAt'        => $promo->getStartsAt()?->format(\DateTimeInterface::ATOM),
            'expiresAt'       => $promo->getExpiresAt()?->format(\DateTimeInterface::ATOM),
            'section'         => $section
                ? [
                    'id'    => $section->getId(),
                    'title' => $section->getTitle(),
                ]
                : null,
            'category'        => $category
                ? [
                    'id'    => $category->getId(),
                    'title' => $category->getTitle(),
                ]
                : null,
            'product'         => $product
                ? [
                    'id'    => $product->getId(),
                    'title' => $product->getTitle(),
                ]
                : null,
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
