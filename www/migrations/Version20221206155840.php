<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20221206155840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE commandlog (id INT AUTO_INCREMENT NOT NULL, timestamp DATETIME NOT NULL, pp_mode VARCHAR(255) DEFAULT NULL, high_pv_power TINYINT(1) DEFAULT NULL, avg_pv_power INT DEFAULT NULL, avg_power INT DEFAULT NULL, inside_temp DOUBLE PRECISION DEFAULT NULL, water_temp DOUBLE PRECISION DEFAULT NULL, heat_storage_mid_temp DOUBLE PRECISION DEFAULT NULL, avg_clouds INT DEFAULT NULL, log JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE data_archive (id INT AUTO_INCREMENT NOT NULL, connector_id VARCHAR(255) NOT NULL, timestamp DATETIME NOT NULL, discr_type VARCHAR(255) NOT NULL, json_value JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE data_store (id INT AUTO_INCREMENT NOT NULL, connector_id VARCHAR(255) NOT NULL, timestamp DATETIME NOT NULL, discr_type VARCHAR(255) NOT NULL, json_value JSON DEFAULT NULL, bool_value TINYINT(1) DEFAULT NULL, INDEX connector_timestamp_idx (connector_id, timestamp), INDEX discr_type_connector_idx_timestamp (discr_type, connector_id, timestamp), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE reset_password_request (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, selector VARCHAR(20) NOT NULL, hashed_token VARCHAR(100) NOT NULL, requested_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', expires_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7CE748AA76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE settings (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(255) DEFAULT NULL, connector_id VARCHAR(255) NOT NULL, mode INT DEFAULT NULL, config JSON DEFAULT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, is_verified TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_8D93D649E7927C74 (email), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reset_password_request ADD CONSTRAINT FK_7CE748AA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reset_password_request DROP FOREIGN KEY FK_7CE748AA76ED395');
        $this->addSql('DROP TABLE commandlog');
        $this->addSql('DROP TABLE data_archive');
        $this->addSql('DROP TABLE data_store');
        $this->addSql('DROP TABLE reset_password_request');
        $this->addSql('DROP TABLE settings');
        $this->addSql('DROP TABLE user');
    }
}
