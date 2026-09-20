<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClinicalTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryUploads = [];

    private function realUpload(string $name, string $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'clinical-test-');
        file_put_contents($path, $bytes);
        $this->temporaryUploads[] = $path;

        return new UploadedFile($path, $name, null, null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryUploads as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }parent::tearDown();
    }

    private User $owner;

    private User $secretary;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('clinical');
        $this->owner = User::factory()->create(['role_id' => Role::where('code', 'psychologist')->firstOrFail()->id]);
        DB::table('practice')->insert(['id' => 1, 'owner_user_id' => $this->owner->id, 'display_name' => 'Teste', 'contact_phone' => '11999990000']);
        $this->secretary = User::factory()->create()->refresh();
        $this->patient = Patient::factory()->create(['created_by' => $this->owner->id]);
    }

    private function loginAs(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth_version' => $user->session_version]);
    }

    private function base(string $module = 'prontuario'): string
    {
        return '/pacientes/'.$this->patient->id.'/'.$module;
    }

    private function createEntry(string $status = 'draft'): int
    {
        $this->loginAs($this->owner)->post($this->base(), ['content' => 'Conteudo confidencial ficticio', 'status' => $status])->assertSessionHasNoErrors();

        return DB::table('clinical_entries')->max('id');
    }

    private function upload(): object
    {
        $this->loginAs($this->owner)->post($this->base('documentos'), ['category' => 'report', 'document' => $this->realUpload('segredo.pdf', "%PDF-1.4\nDocumento ficticio\n%%EOF")])->assertSessionHasNoErrors();

        return DB::table('documents')->first();
    }

    public function test_secretary_cannot_access_any_clinical_endpoint_even_with_injected_permissions(): void
    {
        $id = $this->createEntry();
        $document = $this->upload();
        $this->secretary->role->permissions()->syncWithoutDetaching(Permission::whereIn('code', ['clinical.manage', 'documents.manage'])->pluck('id')->all());
        $this->loginAs($this->secretary->fresh());
        foreach ([$this->base(), $this->base().'/'.$id, $this->base('documentos'), $this->base('documentos').'/'.$document->id.'/download'] as $url) {
            $this->get($url)->assertForbidden()->assertDontSee('Conteudo confidencial');
        }
        $this->post($this->base(), [])->assertForbidden();
        $this->post($this->base().'/'.$id.'/revisoes', [])->assertForbidden();
        $this->post($this->base('documentos'), [])->assertForbidden();
        $this->put($this->base('documentos').'/'.$document->id.'/arquivo', [])->assertForbidden();
        $this->get('/pacientes')->assertOk()->assertDontSee('segredo.pdf')->assertDontSee('Conteudo confidencial')->assertDontSee('Prontuário');
    }

    public function test_content_is_encrypted_audited_and_rendered_escaped(): void
    {
        $id = $this->createEntry();
        $row = DB::table('clinical_entry_versions')->first();
        $this->assertStringNotContainsString('Conteudo confidencial', $row->content_ciphertext);
        $this->assertSame('Conteudo confidencial ficticio', Crypt::decryptString($row->content_ciphertext));
        $this->get($this->base())->assertOk();
        $this->get($this->base().'/'.$id)->assertOk()->assertSee('Conteudo confidencial ficticio')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertDatabaseHas('audit_events', ['action' => 'clinical.read', 'entity_id' => $id]);
        $this->assertStringNotContainsString('Conteudo confidencial', json_encode(DB::table('audit_events')->get()));
        $this->post($this->base().'/'.$id.'/revisoes', ['content' => '<script>alert(1)</script>', 'status' => 'draft', 'current_version' => 1])->assertSessionHasNoErrors();
        $this->get($this->base().'/'.$id)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_finalization_and_amendments_preserve_all_versions(): void
    {
        $id = $this->createEntry('final');
        $original = DB::table('clinical_entry_versions')->first();
        $url = $this->base().'/'.$id.'/revisoes';
        $data = ['content' => 'Correcao ficticia', 'status' => 'final', 'current_version' => 1];
        $this->post($url, $data)->assertSessionHasErrors('amendment_reason');
        $this->post($url, $data + ['amendment_reason' => 'Motivo confidencial'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('clinical_entry_versions', 2);
        $this->assertEquals($original, DB::table('clinical_entry_versions')->where('version', 1)->first());
        $latest = DB::table('clinical_entry_versions')->where('version', 2)->first();
        $this->assertNotNull($latest->finalized_at);
        $this->assertSame('Motivo confidencial', Crypt::decryptString($latest->reason_ciphertext));
        $this->post($url, $data + ['amendment_reason' => 'Reenvio'])->assertSessionHasErrors('record');
        $this->assertDatabaseCount('clinical_entry_versions', 2);
    }

    public function test_sensitive_input_is_not_flashed_after_validation_errors(): void
    {
        $this->loginAs($this->owner)->post($this->base(), ['content' => 'Segredo em erro', 'status' => 'invalid', 'amendment_reason' => 'Outro segredo'])->assertSessionHasErrors('status');
        $this->assertNull(session()->getOldInput('content'));
        $this->assertNull(session()->getOldInput('amendment_reason'));
    }

    public function test_patient_scope_and_appointment_ownership_are_enforced(): void
    {
        $id = $this->createEntry();
        $document = $this->upload();
        $other = Patient::factory()->create(['created_by' => $this->owner->id]);
        $appointment = Appointment::factory()->create(['patient_id' => $other->id]);
        $this->get('/pacientes/'.$other->id.'/prontuario/'.$id)->assertNotFound();
        $this->get('/pacientes/'.$other->id.'/documentos/'.$document->id.'/download')->assertNotFound();
        $this->post($this->base(), ['content' => 'Ficticio', 'status' => 'draft', 'appointment_id' => $appointment->id])->assertSessionHasErrors('appointment_id');
        $this->post($this->base('documentos'), ['category' => 'report', 'appointment_id' => $appointment->id, 'document' => UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\n%%EOF")])->assertSessionHasErrors('appointment_id');
        $this->assertDatabaseCount('clinical_entries', 1);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_documents_are_encrypted_private_and_downloads_match_original(): void
    {
        $doc = $this->upload();
        $cipher = Storage::disk('clinical')->get($doc->storage_key);
        $this->assertStringNotContainsString('Documento ficticio', $cipher);
        $this->assertStringNotContainsString('segredo.pdf', $doc->original_name_ciphertext);
        $this->get($this->base('documentos'))->assertOk()->assertSee('segredo.pdf');
        $this->get($this->base('documentos').'/'.$doc->id.'/download')->assertOk()->assertContent("%PDF-1.4\nDocumento ficticio\n%%EOF")->assertHeader('Content-Disposition', 'attachment; filename="documento-'.$doc->id.'.pdf"');
        $this->get('/storage/clinical/'.$doc->storage_key)->assertNotFound();
        $this->get('/storage/'.$doc->storage_key)->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'documents.downloaded', 'entity_id' => $doc->id]);
    }

    public function test_archive_and_restore_preserve_file_and_reject_stale_updates(): void
    {
        $doc = $this->upload();
        $url = $this->base('documentos').'/'.$doc->id;
        $this->put($url.'/arquivo', ['lock_version' => 1, 'archived' => 1])->assertSessionHasNoErrors();
        $this->get($url.'/download')->assertNotFound();
        Storage::disk('clinical')->assertExists($doc->storage_key);
        $this->put($url.'/arquivo', ['lock_version' => 1, 'archived' => 0])->assertSessionHasErrors('document');
        $this->put($url.'/arquivo', ['lock_version' => 2, 'archived' => 0])->assertSessionHasNoErrors();
        $this->get($url.'/download')->assertOk();
    }

    public function test_invalid_mime_extension_and_size_are_rejected(): void
    {
        $this->loginAs($this->owner);
        foreach ([$this->realUpload('fake.pdf', '<?php echo "x";'), $this->realUpload('payload.php', "%PDF-1.4\n%%EOF"), UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf')] as $file) {
            $this->post($this->base('documentos'), ['category' => 'report', 'document' => $file])->assertSessionHasErrors('document');
        }
        $this->assertDatabaseCount('documents', 0);
        $this->assertCount(0, Storage::disk('clinical')->allFiles());
    }

    public function test_missing_and_tampered_files_are_not_downloaded(): void
    {
        $doc = $this->upload();
        Storage::disk('clinical')->put($doc->storage_key, Crypt::encryptString('Modified'));
        $this->get($this->base('documentos').'/'.$doc->id.'/download')->assertStatus(409);
        Storage::disk('clinical')->delete($doc->storage_key);
        $this->get($this->base('documentos').'/'.$doc->id.'/download')->assertNotFound();
    }

    public function test_upload_failure_rolls_back_metadata_and_audit(): void
    {
        $this->loginAs($this->owner);
        Storage::shouldReceive('disk')->with('clinical')->andReturn($disk = \Mockery::mock());
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('Storage failure'));
        $disk->shouldReceive('delete')->once();
        $this->post($this->base('documentos'), ['category' => 'report', 'document' => UploadedFile::fake()->createWithContent('x.pdf',"%PDF-1.4\n%%EOF")])->assertStatus(500);
        $this->assertDatabaseCount('documents',0);
        $this->assertDatabaseMissing('audit_events',['action' => 'documents.uploaded']);
    }
}
