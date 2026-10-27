<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261027210000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Sites that follow a Git repository, and their deploys';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version3() as $statement) {
            $this->addSql($statement);
        }
    }
}
