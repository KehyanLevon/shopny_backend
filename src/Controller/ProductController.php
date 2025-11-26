<?php

namespace App\Controller;

use App\Entity\Product;
use App\Enum\ProductStatus;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/products', name: 'api_products_')]
class ProductController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        ProductRepository $repo,
        Request $request
    ): JsonResponse {
        $categoryId = $request->query->get('categoryId');
        $sectionId  = $request->query->get('sectionId');

        $qb = $repo->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->leftJoin('c.section', 's')
            ->addSelect('c', 's');

        if ($categoryId !== null) {
            $qb->andWhere('c.id = :categoryId')->setParameter('categoryId', (int)$categoryId);
        }

        if ($sectionId !== null) {
            $qb->andWhere('s.id = :sectionId')->setParameter('sectionId', (int)$sectionId);
        }

        $products = $qb->getQuery()->getResult();

        return $this->json(array_map(
            fn(Product $p) => $this->serializeProduct($p),
            $products
        ));
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        return $this->json($this->serializeProduct($product));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
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

        $em->persist($product);
        $em->flush();

        return $this->json($this->serializeProduct($product), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
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
        if (isset($payload['discountPrice'])) {
            $product->setDiscountPrice($payload['discountPrice']);
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

        $product->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json($this->serializeProduct($product));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
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
