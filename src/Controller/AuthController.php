<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AppMailer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTDecodeFailureException;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\JWTEncodeFailureException;
use LogicException;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/auth', name: 'api_auth_')]
#[OA\Tag(name: 'Auth')]
class AuthController extends AbstractController
{
    #[Route('/register', name: 'register', methods: ['POST'])]
    #[OA\Post(
        summary: 'Register a new user',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password', 'name', 'surname'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                    new OA\Property(property: 'name', type: 'string'),
                    new OA\Property(property: 'surname', type: 'string'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'User registered, verification email sent',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Validation error or email already registered',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Failed to generate verification token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        UserRepository $userRepo,
        JWTEncoderInterface $jwtEncoder,
        AppMailer $mailer,
        UrlGeneratorInterface $urlGenerator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;
        $name = $data['name'] ?? null;
        $surname = $data['surname'] ?? null;

        if (!$email || !$password || !$name || !$surname) {
            return $this->json(['error' => 'All fields are required'], 400);
        }

        if ($userRepo->findOneBy(['email' => $email])) {
            return $this->json(['error' => 'Email already registered'], 400);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setName($name);
        $user->setSurname($surname);
        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);
        $user->setVerifiedAt(null);
        $user->setRoles(['ROLE_USER']);
        $em->persist($user);
        $em->flush();

        try {
            $verificationToken = $jwtEncoder->encode([
                'user_id' => $user->getId(),
                'email'   => $user->getEmail(),
                'type'    => 'email_verification',
                'exp'     => time() + 600,
            ]);
        } catch (JWTEncodeFailureException $e) {
            return $this->json([
                'error'   => 'Failed to generate verification token',
                'details' => $e->getMessage(),
            ], 500);
        }

        $verifyUrl = $urlGenerator->generate(
            'api_auth_verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $message = (new Email())
            ->from('no-reply@shopny.local')
            ->to($user->getEmail())
            ->subject('Verify your email')
            ->text("Click the link to verify your email: $verifyUrl");
        $mailer->send($message);

        return $this->json([
            'message' => 'User registered. Please check your email to verify your account.',
        ], 201);
    }

    #[Route('/login', name: 'login', methods: ['POST'])]
    #[OA\Post(
        summary: 'Login and obtain JWT token',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Successful login, returns JWT token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'token', description: 'JWT access token', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Invalid credentials',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function login(): JsonResponse
    {
        throw new LogicException('This method is blank because the route is handled by the json_login firewall.');
    }

    #[Route('/me', name: 'me', methods: ['GET'])]
    #[OA\Get(
        summary: 'Get current authenticated user info',
        security: [['Bearer' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Current user profile',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'id', type: 'integer'),
                        new OA\Property(property: 'email', type: 'string', format: 'email'),
                        new OA\Property(property: 'name', type: 'string'),
                        new OA\Property(property: 'surname', type: 'string'),
                        new OA\Property(
                            property: 'roles',
                            type: 'array',
                            items: new OA\Items(type: 'string')
                        ),
                        new OA\Property(property: 'verified', type: 'boolean'),
                    ]
                )
            ),
            new OA\Response(
                response: 401,
                description: 'Unauthorized',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function me(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json([
            'id'       => $user->getId(),
            'email'    => $user->getEmail(),
            'name'     => $user->getName(),
            'surname'  => $user->getSurname(),
            'roles'    => $user->getRoles(),
            'verified' => $user->isVerified(),
        ]);
    }

    #[Route('/verify-email', name: 'verify_email', methods: ['GET'])]
    #[OA\Get(
        summary: 'Verify email using token from email link',
        security: [],
        parameters: [
            new OA\QueryParameter(
                name: 'token',
                description: 'Email verification JWT token from email link',
                required: true,
                schema: new OA\Schema(type: 'string')
            )
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Email verified or already verified',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Missing/invalid token or invalid payload',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'User not found for token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
        ]
    )]
    public function verifyEmail(
        Request $request,
        JWTEncoderInterface $jwtEncoder,
        EntityManagerInterface $em,
        UserRepository $userRepo
    ): JsonResponse {
        $token = $request->query->get('token');

        if (!$token) {
            return $this->json(['error' => 'Missing token'], 400);
        }

        try {
            $payload = $jwtEncoder->decode($token);
        } catch (JWTDecodeFailureException) {
            return $this->json(['error' => 'Invalid or expired token'], 400);
        }

        if (($payload['type'] ?? null) !== 'email_verification') {
            return $this->json(['error' => 'Invalid token type'], 400);
        }

        $userId = $payload['user_id'] ?? null;
        $email  = $payload['email'] ?? null;

        if (!$userId || !$email) {
            return $this->json(['error' => 'Invalid token payload'], 400);
        }

        /** @var User|null $user */
        $user = $userRepo->find($userId);

        if (!$user || $user->getEmail() !== $email) {
            return $this->json(['error' => 'User not found for this token'], 404);
        }

        if ($user->isVerified()) {
            return $this->json(['message' => 'Account already verified']);
        }

        $user->setVerifiedAt(new DateTimeImmutable());
        $em->flush();

        return $this->json(['message' => 'Email successfully verified. You can now log in.']);
    }

    #[Route('/resend-verification', name: 'resend_verification', methods: ['POST'])]
    #[OA\Post(
        summary: 'Resend verification email',
        security: [],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Verification email resent (or message is generic to avoid leaking emails)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'message', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Email is missing',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 429,
                description: 'Too many resend attempts',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 500,
                description: 'Failed to generate verification token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'error', type: 'string'),
                        new OA\Property(property: 'details', type: 'string', nullable: true),
                    ]
                )
            ),
        ]
    )]
    public function resendVerification(
        Request $request,
        UserRepository $userRepo,
        JWTEncoderInterface $jwtEncoder,
        AppMailer $mailer,
        UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'limiter.resend_verification')]
        RateLimiterFactory $resendVerificationLimiter,
    ): JsonResponse {
        $data  = json_decode($request->getContent(), true);
        $email = $data['email'] ?? null;

        if (!$email) {
            return $this->json(['error' => 'Email is required'], 400);
        }

        /** @var User|null $user */
        $user = $userRepo->findOneBy(['email' => $email]);

        if (!$user) {
            return $this->json(['message' => 'If this email exists, a verification link has been sent.']);
        }

        if ($user->isVerified()) {
            return $this->json(['message' => 'Account is already verified.']);
        }

        $key     = $request->getClientIp() . '_' . $email;
        $limiter = $resendVerificationLimiter->create($key);

        if (!$limiter->consume(1)->isAccepted()) {
            return $this->json([
                'error' => 'Too many requests. Please try again later.',
            ], 429);
        }

        try {
            $verificationToken = $jwtEncoder->encode([
                'user_id' => $user->getId(),
                'email'   => $user->getEmail(),
                'type'    => 'email_verification',
                'exp'     => time() + 600,
            ]);
        } catch (JWTEncodeFailureException $e) {
            return $this->json([
                'error'   => 'Failed to generate verification token',
                'details' => $e->getMessage(),
            ], 500);
        }

        $verifyUrl = $urlGenerator->generate(
            'api_auth_verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $message = (new Email())
            ->from('no-reply@shopny.local')
            ->to($user->getEmail())
            ->subject('Verify your email (resend)')
            ->text("Click the link to verify your email: $verifyUrl");

        $mailer->send($message);

        return $this->json([
            'message' => 'Verification email has been resent (if the account exists and is not verified).',
        ]);
    }
}
