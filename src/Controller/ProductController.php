<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/products', name: 'api_products_')]
class ProductController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ProductRepository $repo): JsonResponse
    {
        $products = $repo->findAll();

        $data = array_map(
            fn(Product $p) => [
                'id'            => $p->getId(),
                'title'         => $p->getTitle(),
                'price'         => $p->getPrice(),
                'description'   => $p->getDescription(),
                'status'        => $p->getStatus()->value,
                'isActive'      => $p->isActive(),
                'categoryId'    => $p->getCategory()?->getId(),
                'categoryTitle' => $p->getCategory()?->getTitle(),
                'sectionId'     => $p->getCategory()?->getSection()?->getId(),
                'SectionTitle'  => $p->getCategory()?->getSection(),
                'createdAt'     => $p->getCreatedAt()?->format(DATE_ATOM),
            ],
            $products
        );

        return $this->json($data);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        return $this->json([
            'id'          => $product->getId(),
            'title'       => $product->getTitle(),
            'price'       => $product->getPrice(),
            'description' => $product->getDescription(),
            'status'      => $product->getStatus()->value,
            'isActive'    => $product->isActive(),
            'categoryId'  => $product->getCategory()?->getId(),
            'sectionId'   => $product->getCategory()?->getSection()?->getId(),
            'createdAt'   => $product->getCreatedAt()?->format(DATE_ATOM),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (empty($payload['title']) || !isset($payload['price'])) {
            return $this->json(['error' => 'title and price are required'], 400);
        }

        $product = new Product();
        $product->setTitle($payload['title']);
        $product->setPrice((float) $payload['price']);
        $product->setDescription($payload['description'] ?? null);

        $em->persist($product);
        $em->flush();

        return $this->json(['id' => $product->getId()], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(Product $product, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (isset($payload['title'])) {
            $product->setTitle($payload['title']);
        }
        if (isset($payload['price'])) {
            $product->setPrice((float) $payload['price']);
        }
        if (array_key_exists('description', $payload)) {
            $product->setDescription($payload['description']);
        }

        $em->flush();

        return $this->json(['message' => 'Updated']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Product $product, EntityManagerInterface $em): JsonResponse
    {
        $em->remove($product);
        $em->flush();

        return $this->json(null, 204);
    }
}
