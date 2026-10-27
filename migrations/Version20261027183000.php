<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261027183000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'The registry: sites, domains, operations and their logs';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version1() as $statement) {
            $this->addSql($statement);
        }
    }
}
