<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261031190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'A Compose project belongs to one site at most';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version7() as $statement) {
            $this->addSql($statement);
        }
    }
}
