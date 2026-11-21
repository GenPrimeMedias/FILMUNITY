<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LegacyMembershipPreview;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Shuchkin\SimpleXLSX;
use Tests\Support\FinanceWorkflowFixtures;
use Tests\TestCase;

class LegacyMembershipPreviewTest extends TestCase
{
    use DatabaseTransactions;
    use FinanceWorkflowFixtures;

    public function test_legacy_membership_preview_real_csv_xls_xlsx_preserves_identifiers_without_writes(): void
    {
        [$owner, $id] = $this->association(true);
        $this->actingAs($owner);
        $mapping = ['number' => 0, 'name' => 1, 'email' => 2, 'category' => 3, 'starts_on' => 4, 'ends_on' => 5, 'phone' => 6];
        $params = ['sheet' => 0, 'header_row' => 1, 'delimiter' => 'comma', 'source_timezone' => 'Asia/Kolkata', 'mapping' => $mapping];
        $csv = "Old No,Name,Email,Category,Joined,Until,Phone\n000123,Synthetic Member,synthetic.member@example.test,yearly,2020-01-01,2027-01-01,+910001234567\n000124,Synthetic Life,synthetic.life@example.test,Life-Time,2019-01-01,,00098765\n";
        $before = [];
        foreach (['users', 'association_memberships', 'association_membership_numbering', 'association_membership_number_events', 'association_finance_vouchers', 'association_finance_lines', 'association_payment_receipts'] as $table) {
            $before[$table] = DB::table($table)->orderByRaw('1')->get()->toJson();
        }
        $url = "/association-members/$id/legacy-preview";
        $this->postJson($url, [...$params, 'file' => UploadedFile::fake()->createWithContent('members.csv', $csv)])->assertOk()->assertJsonPath('report.summary.ready', 2)->assertJsonPath('report.rows.0.values.number', '000123')->assertJsonPath('report.rows.1.values.phone', '00098765')->assertJsonPath('preview_only', true);
        $xls = base64_decode(trim(file_get_contents(base_path('tests/Fixtures/legacy-members-preview.xls.b64'))), true);
        $response = $this->postJson($url, [...$params, 'file' => UploadedFile::fake()->createWithContent('members.xls', $xls)])->assertOk()->assertJsonPath('report.summary.ready', 2)->assertJsonPath('report.summary.needs_correction', 2)->assertJsonPath('report.rows.0.values.number', '000123');
        $this->assertStringContainsString('Numeric member number', implode(' ', $response->json('report.rows.2.reasons')));
        $this->assertStringContainsString('formula', implode(' ', $response->json('report.rows.3.reasons')));
        $xlsx = $this->legacyXlsxFixture();
        $this->postJson($url, [...$params, 'file' => UploadedFile::fake()->createWithContent('members.xlsx', $xlsx)])->assertOk()->assertJsonPath('report.summary.ready', 2)->assertJsonPath('report.rows.0.values.number', '000123')->assertJsonPath('report.rows.1.values.number', '000125')->assertJsonPath('report.rows.1.values.starts_on', '2020-01-01');
        $this->postJson($url, [...$params, 'file' => UploadedFile::fake()->createWithContent('renamed.xls', '<html>Not an XLS</html>')])->assertUnprocessable();
        $this->postJson($url, [...$params, 'file' => UploadedFile::fake()->create('large.csv', 2049)])->assertUnprocessable();
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderByRaw('1')->get()->toJson(), "$table must remain unchanged");
        }
        Mail::assertNothingSent();
    }

    public function test_legacy_preview_flags_source_rows_duplicates_dates_missing_fields_and_tenant_matches(): void
    {
        [$owner, $id] = $this->association(true);
        [$party] = $this->member($owner, $id);
        $membership = DB::table('association_finance_accounts')->where('id', $party)->value('membership_id');
        DB::table('association_memberships')->where('id', $membership)->update(['number' => '000777']);
        $memberId = DB::table('association_memberships')->where('id', $membership)->value('member_id');
        DB::table('users')->where('id', $memberId)->update(['name' => 'Existing Synthetic', 'email' => 'existing.synthetic@example.test']);
        $csv = "No,Name,Email,Category,Joined,Until\n000777,Existing Synthetic,existing.synthetic@example.test,yearly,2020-01-01,2027-01-01\nAbC-001,Duplicate One,duplicate.one@example.test,yearly,2020-01-01,2027-01-01\nabc-001,Duplicate Two,duplicate.two@example.test,yearly,2020-01-01,2027-01-01\n000888,Existing Synthetic,different@example.test,yearly,2020-01-01,2027-01-01\n,Missing Fields,,unknown,2026-02-30,2020-01-01\n\n000999,New Synthetic,new.synthetic@example.test,lifetime,2020-01-01,\n";
        $params = ['sheet' => 0, 'header_row' => 1, 'delimiter' => 'comma', 'source_timezone' => 'Asia/Kolkata', 'mapping' => ['number' => 0, 'name' => 1, 'email' => 2, 'category' => 3, 'starts_on' => 4, 'ends_on' => 5]];
        $this->actingAs($owner)->get('/association-members/legacy-preview')->assertOk();
        $response = $this->postJson("/association-members/$id/legacy-preview", [...$params, 'file' => UploadedFile::fake()->createWithContent('review.csv', $csv)])->assertOk()->assertJsonPath('report.summary.existing_matches', 1)->assertJsonPath('report.summary.ready', 1)->assertJsonPath('report.summary.needs_correction', 3)->assertJsonPath('report.summary.manual_review', 1)->assertJsonPath('report.rows.0.status', 'existing_matches')->assertJsonPath('report.rows.5.source_row', 8);
        $this->assertStringContainsString('source rows 3, 4', implode(' ', $response->json('report.rows.1.reasons')));
        $this->assertStringContainsString('invalid', implode(' ', $response->json('report.rows.4.reasons')));
        $this->assertStringContainsString('missing', implode(' ', $response->json('report.rows.4.reasons')));
        $missingMapping = $params;
        unset($missingMapping['mapping']['number']);
        $this->postJson("/association-members/$id/legacy-preview", [...$missingMapping, 'file' => UploadedFile::fake()->createWithContent('review.csv', $csv)])->assertOk()->assertJsonPath('report.summary.ready', 0);
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->postJson("/association-members/$id/legacy-preview", [...$params, 'file' => UploadedFile::fake()->createWithContent('review.csv', $csv)])->assertNotFound();
        $this->postJson("/association-members/$otherId/legacy-preview", [...$params, 'file' => UploadedFile::fake()->createWithContent('review.csv', $csv)])->assertOk()->assertJsonPath('report.summary.existing_matches', 0)->assertJsonPath('report.rows.0.matches', []);
        $this->actingAs(User::factory()->create())->get('/association-members/legacy-preview')->assertNotFound();
    }

    public function test_legacy_preview_shared_contacts_mapping_errors_and_required_timezone(): void
    {
        [$owner, $id] = $this->association(true);
        $this->actingAs($owner);
        $url = "/association-members/$id/legacy-preview";
        $csv = "Number;Name;Email;Category;Joined;Until\n000000000000000000000001;Synthetic One;shared@example.test;yearly;2020-01-01;2027-01-01\n000002;Synthetic Two;shared@example.test;yearly;2020-01-01;2027-01-01\n";
        $params = ['sheet' => 0, 'header_row' => 1, 'delimiter' => 'semicolon', 'source_timezone' => 'Asia/Kolkata', 'mapping' => ['number' => 0, 'name' => 1, 'email' => 2, 'category' => 3, 'starts_on' => 4, 'ends_on' => 5]];
        $file = fn () => UploadedFile::fake()->createWithContent('shared.csv', $csv);
        $this->postJson($url, [...$params, 'file' => $file()])->assertOk()->assertJsonPath('report.summary.manual_review', 2)->assertJsonPath('report.rows.0.values.number', '000000000000000000000001');
        $this->postJson($url, [...$params, 'source_timezone' => null, 'file' => $file()])->assertOk()->assertJsonPath('report.summary.needs_correction', 2);
        $this->postJson($url, [...$params, 'mapping' => [...$params['mapping'], 'name' => 0], 'file' => $file()])->assertUnprocessable();
        $this->postJson($url, [...$params, 'sheet' => 1, 'file' => $file()])->assertUnprocessable();
        $this->postJson($url, [...$params, 'source_timezone' => 'Invalid/Zone', 'file' => $file()])->assertUnprocessable();
    }

    public function test_downloaded_filmunity_templates_auto_map_and_preserve_example_text_without_writes(): void
    {
        [$owner, $id] = $this->association(true);
        $this->actingAs($owner);
        $url = "/association-members/$id/legacy-template";
        $this->get($url.'?format=xlsx')->assertOk()->assertDownload('filmunity-members-blank.xlsx');
        $this->get($url.'?format=example')->assertOk()->assertDownload('filmunity-members-example.xlsx');
        $csv = $this->get($url.'?format=csv')->assertOk()->assertDownload('filmunity-members-blank.csv')->getContent();
        $this->assertSame("\xEF\xBB\xBF".implode(',', LegacyMembershipPreview::FIELDS)."\r\n", $csv);
        $this->assertSame($csv, file_get_contents(resource_path('templates/legacy-membership/filmunity-members-blank.csv')));
        $params = ['sheet' => 0, 'header_row' => 1, 'delimiter' => 'comma', 'source_timezone' => 'Asia/Kolkata'];
        $preview = "/association-members/$id/legacy-preview";
        $before = [];
        foreach (['users', 'association_memberships', 'association_membership_numbering', 'association_finance_lines'] as $table) {
            $before[$table] = DB::table($table)->orderByRaw('1')->get()->toJson();
        }
        $examplePath = resource_path('templates/legacy-membership/filmunity-members-example.xlsx');
        $response = $this->postJson($preview, [...$params, 'file' => UploadedFile::fake()->createWithContent('example.xlsx', file_get_contents($examplePath))])->assertOk()->assertJsonPath('mapping_mode', 'automatic')->assertJsonPath('report.summary.ready', 2)->assertJsonPath('report.summary.needs_correction', 0)->assertJsonPath('report.rows.0.values.number', '000123')->assertJsonPath('report.rows.1.values.phone', '0009876543')->assertJsonPath('report.rows.0.values.starts_on', '2020-01-01');
        $this->assertStringContainsString('செயற்கை உறுப்பினர்', $response->json('report.rows.0.values.name'));
        $this->postJson($preview, [...$params, 'file' => UploadedFile::fake()->createWithContent('blank.xlsx', file_get_contents(resource_path('templates/legacy-membership/filmunity-members-blank.xlsx')))])->assertOk()->assertJsonPath('mapping_mode', 'automatic')->assertJsonPath('report.summary.ready', 0)->assertJsonPath('report.rows', []);
        $this->postJson($preview, [...$params, 'file' => UploadedFile::fake()->createWithContent('blank.csv', $csv)])->assertOk()->assertJsonPath('mapping_mode', 'automatic')->assertJsonPath('report.rows', []);
        $book = SimpleXLSX::parseFile($examplePath);
        $this->assertSame(['Members', 'Guidance'], array_values($book->sheetNames()));
        $cells = $book->rowsEx(0, 3);
        $this->assertSame('@', $cells[1][0]['format']);
        $this->assertSame('@', $cells[2][3]['format']);
        $this->assertSame('000123', $cells[1][0]['value']);
        $this->assertStringContainsString('No status field', json_encode($book->rows(1), JSON_THROW_ON_ERROR));
        $guidance = $book->rowsEx(1, 17);
        $this->assertSame('@', $guidance[16][1]['format']);
        $this->assertSame([], LegacyMembershipPreview::templateMapping(['membership_number', 'full_name', 'email']));
        $this->assertSame([], LegacyMembershipPreview::templateMapping(['number', 'number', 'email', 'phone', 'address', 'category', 'starts_on', 'ends_on']));
        $this->postJson($preview, [...$params, 'mapping' => ['number' => 0], 'file' => UploadedFile::fake()->createWithContent('manual.xlsx', file_get_contents($examplePath))])->assertOk()->assertJsonPath('mapping_mode', 'manual')->assertJsonPath('report.summary.ready', 0);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderByRaw('1')->get()->toJson());
        }
        [$other, $otherId] = $this->association(true);
        $this->actingAs($other)->get($url.'?format=xlsx')->assertNotFound();
        $this->actingAs(User::factory()->create())->get($url.'?format=example')->assertNotFound();
    }

    private function legacyXlsxFixture(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-xlsx-');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Synthetic" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="000000"/></numFmts><fonts count="1"><font/></fonts><fills count="1"><fill/></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $xml = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:G3"/><sheetData>';
        $rows = [['No', 'Name', 'Email', 'Category', 'Joined', 'Until', 'Phone'], ['000123', 'Synthetic Member', 'synthetic.member@example.test', 'yearly', '2020-01-01', '2027-01-01', '+910001234567'], ['NUMERIC', 'Synthetic Formatted', 'formatted@example.test', 'yearly', 'EXCELDATE', '2027-01-01', '00098765']];
        foreach ($rows as $r => $row) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($row as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                $xml .= $value === 'NUMERIC' ? '<c r="'.$ref.'" s="1"><v>125</v></c>' : ($value === 'EXCELDATE' ? '<c r="'.$ref.'" s="2"><v>43831</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>');
            } $xml .= '</row>';
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml.'</sheetData></worksheet>');
        $zip->close();
        try {
            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
