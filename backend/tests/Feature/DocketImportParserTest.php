<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Unit-style tests for ProjectDocketImportController's internal parsing.
 * We drive via the controller's private methods by exposing them through
 * a thin reflection helper so we don't need to spin up an HTTP server.
 *
 * Run: php artisan test --filter=DocketImportParserTest
 */
class DocketImportParserTest extends TestCase
{
    private \ReflectionClass $ref;
    private object $ctrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctrl = new \App\Http\Controllers\ProjectDocketImportController();
        $this->ref  = new \ReflectionClass($this->ctrl);
    }

    private function parse(string $raw, string $defaultOffice = 'IN'): array
    {
        $m = $this->ref->getMethod('parseRefNumber');
        $m->setAccessible(true);
        return $m->invoke($this->ctrl, $raw, $defaultOffice);
    }

    // ── 1. LEGACY_MY6 — simple (no suffix) ────────────────────────────────

    public function test_legacy_my6_plain(): void
    {
        // MY015018 → client group 015, matter_seq 018
        $r = $this->parse('MY015018');
        $this->assertSame('018', $r['seq']);
        $this->assertSame('IN', $r['office_code']);   // fallback to defaultOffice
        $this->assertSame('PAT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 2. LEGACY_MY6 — embedded US country ────────────────────────────────

    public function test_legacy_my6_embedded_us(): void
    {
        // MY015012US → seq=012, office=US
        $r = $this->parse('MY015012US');
        $this->assertSame('012', $r['seq']);
        $this->assertSame('US', $r['office_code']);
        $this->assertSame('PAT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 3. LEGACY_MY6 — with space token "IN" ──────────────────────────────

    public function test_legacy_my6_space_token(): void
    {
        // "MY015006 IN" → seq=006, office=IN via token
        $r = $this->parse('MY015006 IN');
        $this->assertSame('006', $r['seq']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 4. STD_EMBEDDED — PCT ──────────────────────────────────────────────

    public function test_std_embedded_pct(): void
    {
        // 023M039PCT → seq=039, office=WO, service=PCT
        $r = $this->parse('023M039PCT');
        $this->assertSame('039', $r['seq']);
        $this->assertSame('WO', $r['office_code']);
        $this->assertSame('PCT', $r['service_code']);
        $this->assertSame('Patent - PCT', $r['project_type']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 5. STD_EMBEDDED — INP (India Non-Provisional) ─────────────────────

    public function test_std_embedded_inp(): void
    {
        // 269M151INP → seq=151, office=IN, service=CPT
        $r = $this->parse('269M151INP');
        $this->assertSame('151', $r['seq']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertSame('CPT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 6. STD_EMBEDDED — USNP (US Non-Provisional) ───────────────────────

    public function test_std_embedded_usnp(): void
    {
        // 269M088USNP → seq=088, office=US, service=CPT
        $r = $this->parse('269M088USNP');
        $this->assertSame('088', $r['seq']);
        $this->assertSame('US', $r['office_code']);
        $this->assertSame('CPT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 7. STD_SPACE — plain two-char country token ────────────────────────

    public function test_std_space_country_token(): void
    {
        // "023M003 IN" → seq=003, office=IN
        $r = $this->parse('023M003 IN');
        $this->assertSame('003', $r['seq']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertSame('PAT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 8. STD_SPACE — country + service ──────────────────────────────────

    public function test_std_space_country_and_service(): void
    {
        // "068M014 IN DSN" → seq=014, office=IN, service=DSN (Design)
        $r = $this->parse('068M014 IN DSN');
        $this->assertSame('014', $r['seq']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertSame('DSN', $r['service_code']);
        $this->assertSame('Design Patent', $r['project_type']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 9. STD_3TOK — numeric disambiguation (UK 2) ───────────────────────

    public function test_numeric_disambiguator(): void
    {
        // "A00M001 UK 2 DSN" → seq = 001 + (2-1) = 002, office=GB, service=DSN
        $r = $this->parse('A00M001 UK 2 DSN');
        $this->assertSame('002', $r['seq']);
        $this->assertSame('GB', $r['office_code']);
        $this->assertSame('DSN', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 10. LETTER_PFX — alphanumeric client prefix ───────────────────────

    public function test_letter_prefix_client(): void
    {
        // A04Y002 IN → seq=002, office=IN, service=PAT
        $r = $this->parse('A04Y002 IN');
        $this->assertSame('002', $r['seq']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertSame('PAT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 11. SEQ_LETTER — A/B/C variant on seq ─────────────────────────────

    public function test_seq_letter_variant(): void
    {
        // "269M060A INC" → seq=060, seqLetter=A (noted in extra), service=INC
        $r = $this->parse('269M060A INC');
        $this->assertSame('060', $r['seq']);
        $this->assertSame('INC', $r['service_code']);
        $this->assertStringContainsString('Seq variant: A', $r['extra_notes']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 12. DIVISIONAL ────────────────────────────────────────────────────

    public function test_divisional_d1(): void
    {
        // "042M003 D1 IN" → seq=003, office=IN, service=DIV
        $r = $this->parse('042M003 D1 IN');
        $this->assertSame('003', $r['seq']);
        $this->assertSame('DIV', $r['service_code']);
        $this->assertSame('IN', $r['office_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 13. PCT space token ────────────────────────────────────────────────

    public function test_pct_space_token(): void
    {
        // "097Y007 PCT" → seq=007, office=WO, service=PCT
        $r = $this->parse('097Y007 PCT');
        $this->assertSame('007', $r['seq']);
        $this->assertSame('WO', $r['office_code']);
        $this->assertSame('PCT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 14. EP validated (EP + 2-char country) ────────────────────────────

    public function test_ep_validated(): void
    {
        // "269Y001 EP DE" → office=EP, extra note "Validated in DE"
        $r = $this->parse('269Y001 EP DE');
        $this->assertSame('001', $r['seq']);
        $this->assertSame('EP', $r['office_code']);
        $this->assertStringContainsString('Validated in DE', $r['extra_notes']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 15. LEGACY_NUMERIC — all-digit base ───────────────────────────────

    public function test_legacy_numeric(): void
    {
        // "157199 DSN" → seq=199 (last 3), service=DSN
        $r = $this->parse('157199 DSN');
        $this->assertSame('199', $r['seq']);
        $this->assertSame('DSN', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 16. Embedded USP (US granted patent) ──────────────────────────────

    public function test_embedded_usp(): void
    {
        $r = $this->parse('023M012USP');
        $this->assertSame('012', $r['seq']);
        $this->assertSame('US', $r['office_code']);
        $this->assertSame('PAT', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 17. NPA / NPEP (non-provisional EP) ───────────────────────────────

    public function test_npep_token(): void
    {
        $r = $this->parse('023M047 NPEP');
        $this->assertSame('047', $r['seq']);
        $this->assertSame('NPA', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }

    // ── 18. AU divisional ────────────────────────────────────────────────

    public function test_au_divisional(): void
    {
        // "097Y007 AU DIV" → office=AU, service=DIV
        $r = $this->parse('097Y007 AU DIV');
        $this->assertSame('007', $r['seq']);
        $this->assertSame('AU', $r['office_code']);
        $this->assertSame('DIV', $r['service_code']);
        $this->assertFalse($r['parse_error']);
    }
}
