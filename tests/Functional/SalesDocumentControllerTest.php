<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\SalesDocument;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional tests for SalesDocumentController driven through real HTTP requests against the test
 * kernel, asserting both the success payload and the status codes that domain errors map to.
 *
 * Covers:
 * 1. Creating a quote and approving it over HTTP spawns a linked order in the response.
 * 2. Approving an unknown document id is a client error, answered with 404 and a stable error code.
 * 3. Approving a document that is no longer a draft conflicts with its state, answered with 409
 *    and a stable error code.
 */
final class SalesDocumentControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        self::getContainer()->get('doctrine.orm.entity_manager')
            ->createQuery('DELETE FROM ' . SalesDocument::class)
            ->execute();
    }

    public function testCreateAndApproveThroughHttp(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        self::assertResponseStatusCodeSame(201);
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));
        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('order', $body['type']);
        self::assertSame($quoteId, $body['parent_quote_id']);
    }

    public function testApprovingMissingDocumentReturns404(): void
    {
        $this->client->request('POST', '/sales-documents/999999/approve', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));

        self::assertResponseStatusCodeSame(404);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('sales_document_not_found', $body['error']);
    }

    public function testApprovingAnAlreadyApprovedDocumentReturns409(): void
    {
        $this->client->request('POST', '/sales-documents', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'contractor_id' => 77,
            'created_by' => 5,
        ]));
        $quoteId = json_decode($this->client->getResponse()->getContent(), true)['id'];

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', "/sales-documents/{$quoteId}/approve", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'approved_by' => 9,
        ]));
        self::assertResponseStatusCodeSame(409);

        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('sales_document_status_conflict', $body['error']);
    }
}
