<?php

declare(strict_types=1);

namespace App\Controller\Api\v1;

use App\DTO\IssueCreateDTO;
use App\DTO\IssueResponseDTO;
use App\DTO\IssueUpdateDTO;
use App\DTO\PaginationRequestDTO;
use App\Entity\IssueStatus;
use App\Entity\IssuePriority;
use App\Entity\User;
use App\Message\EmailNotificationMessage;
use App\Repository\TechnicianRepository;
use App\Service\IssueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/issues', name: 'issues_')]
class IssueController extends AbstractController
{
    public function __construct(
        private readonly IssueService $issueService,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(#[MapQueryString] PaginationRequestDTO $paginationDTO): Response
    {
        $paginatedResponse = $this->issueService->getPaginatedIssues($paginationDTO);

        return $this->json($paginatedResponse);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(#[MapRequestPayload] IssueCreateDTO $dto): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json(['error' => 'Invalid user'], Response::HTTP_UNAUTHORIZED);
        }

        $issue = $this->issueService->createIssue($dto, $user);

        return $this->json(
            IssueResponseDTO::fromEntity($issue),
            Response::HTTP_CREATED
        );
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_USER')]
    public function update(
        Uuid $id,
        #[MapRequestPayload] IssueUpdateDTO $dto,
        TechnicianRepository $technicianRepository,
        MessageBusInterface $messageBus
    ): Response {
        $issue = $this->issueService->getIssue($id);
        if ($issue === null) {
            return $this->json(['error' => 'Not found'], Response::HTTP_NOT_FOUND);
        }

        if ($issue->getUser() !== $this->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            return $this->json(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $issueId = Uuid::fromBinary($issue->getId()->toBinary())->toRfc4122();

        if ($dto->status) {
            $issue->setStatus(IssueStatus::from($dto->status));
            $messageBus->dispatch(new EmailNotificationMessage(
                $issue->getUser()->getEmail(),
                'Zmiana statusu zgłoszenia',
                'emails/issue/status_changed.html.twig',
                [
                    'issue_id' => $issueId,
                    'issue' => $issue,
                    'status' => $issue->getStatus()->value,
                ]
            ));
        }

        if ($dto->priority) {
            $issue->setPriority(IssuePriority::from($dto->priority));
            $messageBus->dispatch(new EmailNotificationMessage(
                $issue->getUser()->getEmail(),
                'Zmiana priorytetu zgłoszenia',
                'emails/issue/priority_changed.html.twig',
                [
                    'issue_id' => $issueId,
                    'issue' => $issue,
                    'priority' => $issue->getPriority()->value,
                ]
            ));
        }

        if ($dto->technicianId) {
            $technician = $technicianRepository->find($dto->technicianId);
            if ($technician === null) {
                return $this->json(['error' => 'Technician not found'], Response::HTTP_BAD_REQUEST);
            }

            $issue->setTechnician($technician);

            // Powiadomienie dla użytkownika
            $messageBus->dispatch(new EmailNotificationMessage(
                $issue->getUser()->getEmail(),
                'Przypisanie technika do zgłoszenia',
                'emails/issue/technician_assigned.html.twig',
                [
                    'issue_id' => $issueId,
                    'issue' => $issue,
                    'technician' => $technician,
                ]
            ));

            // Powiadomienie dla technika
            $messageBus->dispatch(new EmailNotificationMessage(
                $technician->getEmail(),
                'Nowe zgłoszenie do obsługi',
                'emails/issue/new_issue_assigned.html.twig',
                [
                    'issue_id' => $issueId,
                    'issue' => $issue,
                ]
            ));
        }

        $this->issueService->updateIssue($issue);

        return $this->json(IssueResponseDTO::fromEntity($issue));
    }
}
