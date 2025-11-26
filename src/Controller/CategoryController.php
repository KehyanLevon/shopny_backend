<?php

namespace App\Controller;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\SectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/categories', name: 'api_categories_')]
class CategoryController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CategoryRepository $categoryRepository,
        Request $request,
    ): JsonResponse {
        $sectionId = $request->query->get('sectionId');

        if ($sectionId !== null) {
            $categories = $categoryRepository->createQueryBuilder('c')
                ->andWhere('c.section = :sectionId')
                ->setParameter('sectionId', (int) $sectionId)
                ->getQuery()
                ->getResult();
        } else {
            $categories = $categoryRepository->findAll();
        }

        $data = array_map(
            fn (Category $category) => $this->serializeCategory($category),
            $categories
        );

        return $this->json($data);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Category $category): JsonResponse
    {
        return $this->json($this->serializeCategory($category));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SectionRepository $sectionRepository,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }
        if (empty($payload['title'])) {
            return $this->json(['error' => 'title is required'], 400);
        }
        if (empty($payload['sectionId'])) {
            return $this->json(['error' => 'sectionId is required'], 400);
        }
        $section = $sectionRepository->find((int) $payload['sectionId']);
        if (!$section) {
            return $this->json(['error' => 'Section not found'], 404);
        }
        $category = new Category();
        $category->setTitle($payload['title']);
        $category->setSection($section);

        $slug = (string) $slugger
            ->slug($payload['title'])
            ->lower();
        $category->setSlug($slug);

        if (array_key_exists('description', $payload)) {
            $category->setDescription($payload['description']);
        }

        $em->persist($category);
        $em->flush();

        return $this->json($this->serializeCategory($category), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        Category $category,
        Request $request,
        EntityManagerInterface $em,
        SectionRepository $sectionRepository,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $titleChanged = false;

        if (isset($payload['title'])) {
            $category->setTitle($payload['title']);
            $titleChanged = true;
        }

        if (array_key_exists('description', $payload)) {
            $category->setDescription($payload['description']);
        }

        if (isset($payload['sectionId'])) {
            $section = $sectionRepository->find((int) $payload['sectionId']);
            if (!$section) {
                return $this->json(['error' => 'Section not found'], 404);
            }
            $category->setSection($section);
        }

        if ($titleChanged && $category->getTitle() !== null) {
            $newSlug = (string) $slugger
                ->slug($category->getTitle())
                ->lower();
            $category->setSlug($newSlug);
        }
        $category->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json($this->serializeCategory($category));
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
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
}
