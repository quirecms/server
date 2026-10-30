<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261030230000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'The newest build of each runtime image the server built on';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version6() as $statement) {
            $this->addSql($statement);
        }
    }
}
