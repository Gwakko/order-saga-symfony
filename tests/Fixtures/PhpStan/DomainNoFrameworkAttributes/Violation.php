<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[AsMessageHandler]
final class Violation
{
    #[Assert\NotBlank]
    #[Groups(['read'])]
    public string $name = '';

    #[\SensitiveParameter]
    public string $allowed = '';
}
