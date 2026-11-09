<?php

declare(strict_types=1);

namespace Quire\Server\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Quire\Server\Registry\Schema as RegistrySchema;

final class Version20261109190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'The public key this server signs its backups with';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach (RegistrySchema::version12() as $statement) {
            $this->addSql($statement);
        }
    }
}
