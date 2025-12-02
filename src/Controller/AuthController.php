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
use App\Dto\Auth\RegisterRequest;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Dto\Auth\ResendVerificationRequest;

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
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json([
                'message' => 'Invalid JSON body.',
            ], 400);
        }

        $dto = new RegisterRequest();
        $dto->setEmail($data['email'] ?? null);
        $dto->setPassword($data['password'] ?? null);
        $dto->setName($data['name'] ?? null);
        $dto->setSurname($data['surname'] ?? null);

        $violations = $validator->validate($dto);

        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 422);
        }
        if ($userRepo->findOneBy(['email' => $dto->getEmail()])) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => [
                    'email' => ['This email is already registered.'],
                ],
            ], 422);
        }

        $user = new User();
        $user->setEmail($dto->getEmail());
        $user->setName($dto->getName());
        $user->setSurname($dto->getSurname());
        $hashedPassword = $passwordHasher->hashPassword($user, $dto->getPassword());
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
        summary: 'Verify email using the token from the verification email',
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
                description: 'Email verification result (either newly verified or already verified)',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(
                            property: 'status',
                            description: 'Verification result',
                            type: 'string',
                            enum: ['verified', 'already_verified']
                        ),
                        new OA\Property(
                            property: 'message',
                            description: 'Human-friendly description',
                            type: 'string'
                        )
                    ]
                )
            ),
            new OA\Response(
                response: 400,
                description: 'Missing or invalid token, expired token, or invalid payload',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'error'),
                        new OA\Property(property: 'error', type: 'string'),
                    ]
                )
            ),
            new OA\Response(
                response: 404,
                description: 'User not found for this token',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'status', type: 'string', example: 'error'),
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
        if (!is_string($token) || trim($token) === '') {
            return $this->json([
                'status' => 'error',
                'error'  => 'Missing token',
            ], 400);
        }
        try {
            $payload = $jwtEncoder->decode($token);
        } catch (JWTDecodeFailureException) {
            return $this->json([
                'status' => 'error',
                'error'  => 'Invalid or expired token',
            ], 400);
        }
        if (($payload['type'] ?? null) !== 'email_verification') {
            return $this->json([
                'status' => 'error',
                'error'  => 'Invalid token type',
            ], 400);
        }
        $userId = $payload['user_id'] ?? null;
        $email  = $payload['email'] ?? null;
        if (!$userId || !$email) {
            return $this->json([
                'status' => 'error',
                'error'  => 'Invalid token payload',
            ], 400);
        }
        $user = $userRepo->find($userId);
        if (!$user || $user->getEmail() !== $email) {
            return $this->json([
                'status' => 'error',
                'error'  => 'User not found for this token',
            ], 404);
        }
        if ($user->isVerified()) {
            return $this->json([
                'status'  => 'already_verified',
                'message' => 'Account already verified.',
            ], 200);
        }
        $user->setVerifiedAt(new DateTimeImmutable());
        $em->flush();
        return $this->json([
            'status'  => 'verified',
            'message' => 'Email successfully verified. You can now log in.',
        ], 200);
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
        ValidatorInterface $validator,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return $this->json([
                'message' => 'Invalid JSON body.',
            ], 400);
        }
        $dto = new ResendVerificationRequest();
        $dto->email = isset($data['email']) ? trim((string) $data['email']) : null;
        $violations = $validator->validate($dto);

        if (count($violations) > 0) {
            return $this->json([
                'message' => 'Validation failed.',
                'errors'  => $this->formatValidationErrors($violations),
            ], 400);
        }

        $user = $userRepo->findOneBy(['email' => $dto->email]);

        if (!$user) {
            return $this->json([
                'message' => 'If this email exists, a verification link has been sent.',
            ]);
        }

        if ($user->isVerified()) {
            return $this->json([
                'message' => 'Account is already verified.',
            ]);
        }

        $key     = $request->getClientIp() . '_' . $dto->email;
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

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $response = $this->json(['message' => 'Logged out']);

        $response->headers->clearCookie(
            'AUTH_TOKEN',
            '/',
            null,
            true,
            true,
            'lax'
        );

        return $response;
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
