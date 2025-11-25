<?php

namespace App\Controller;

use App\Entity\Section;
use App\Repository\SectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/api/sections', name: 'api_sections_')]
class SectionController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SectionRepository $sectionRepository): JsonResponse
    {
        $sections = $sectionRepository->findAll();

        $data = array_map(
            fn (Section $section) => $this->serializeSection($section),
            $sections
        );

        return $this->json($data);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Section $section): JsonResponse
    {
        return $this->json($this->serializeSection($section));
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        if (empty($payload['title'])) {
            return $this->json(['error' => 'title is required'], 400);
        }

        $section = new Section();
        $section->setTitle($payload['title']);

        $slug = (string) $slugger
            ->slug($payload['title'])
            ->lower();
        $section->setSlug($slug);

        if (array_key_exists('description', $payload)) {
            $section->setDescription($payload['description']);
        }

        $section->setCreatedAt(new \DateTimeImmutable());

        if (isset($payload['isActive'])) {
            $section->setIsActive((bool) $payload['isActive']);
        } else {
            $section->setIsActive(true);
        }

        $em->persist($section);
        $em->flush();

        return $this->json($this->serializeSection($section), 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT', 'PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(
        Section $section,
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON'], 400);
        }

        $titleChanged = false;

        if (isset($payload['title'])) {
            $section->setTitle($payload['title']);
            $titleChanged = true;
        }

        if (array_key_exists('description', $payload)) {
            $section->setDescription($payload['description']);
        }

        if (isset($payload['isActive'])) {
            $section->setIsActive((bool) $payload['isActive']);
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
}
