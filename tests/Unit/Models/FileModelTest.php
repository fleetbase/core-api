<?php

use Fleetbase\Models\File;
use Fleetbase\Models\User;
use Illuminate\Http\Request;

class FileModelSaveSpy extends File
{
    public array $updates = [];
    public int $saves = 0;

    public function save(array $options = []): bool
    {
        $this->saves++;

        return true;
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        $this->updates[] = $attributes;
        $this->forceFill($attributes);

        return true;
    }
}

it('derives file extensions MIME types and hash names from stable metadata', function () {
    bind_test_container();

    $archive = new File();
    $archive->setRawAttributes([
        'original_filename' => 'fleetbase-backup.tar.gz',
        'content_type' => null,
        'path' => 'archives/2026/fleetbase-backup.tar.gz',
    ], true);

    $pdf = new File();
    $pdf->setRawAttributes([
        'original_filename' => 'invoice.unknown',
        'content_type' => 'application/pdf',
        'path' => 'documents/invoice.pdf',
    ], true);

    expect(File::parseExtensionFromFilename('fleetbase-backup.tar.gz'))->toBe('tar.gz')
        ->and(File::parseExtensionFromFilename('photo.JPEG'))->toBe('JPEG')
        ->and(File::getMimeTypeFromExtension('png'))->toBe('image/png')
        ->and(File::getFileMimeType('pdf'))->toBe('application/pdf')
        ->and(File::getExtensionFromMimeType('application/vnd.custom-report'))->toBe('vnd.custom-report')
        ->and($archive->getExtensionFromFilename())->toBe('tar.gz')
        ->and($archive->getExtension())->toBe('tar.gz')
        ->and($archive->hash_name)->toBe('fleetbase-backup.tar.gz')
        ->and($pdf->getExtension())->toBe('pdf')
        ->and($pdf->hash_name)->toBe('invoice.pdf');
});

it('assigns uploaders subjects and file types through model mutators', function () {
    bind_test_container();

    $uploader = new User();
    $uploader->setRawAttributes(['uuid' => 'user-1'], true);

    $subject = new User();
    $subject->setRawAttributes(['uuid' => 'subject-user-1'], true);

    $file = new FileModelSaveSpy();
    $file->setRawAttributes(['uuid' => 'file-1'], true);

    expect($file->setUploader($uploader))->toBe($file)
        ->and($file->uploader_uuid)->toBe('user-1')
        ->and($file->setSubject($subject, 'avatar'))->toBe($file)
        ->and($file->subject_uuid)->toBe('subject-user-1')
        ->and($file->subject_type)->toBe(User::class)
        ->and($file->type)->toBe('avatar')
        ->and($file->setType('document'))->toBe($file)
        ->and($file->type)->toBe('document')
        ->and($file->saves)->toBe(2);
});

it('updates file subjects from request uuid and type-only payloads', function () {
    bind_test_container();

    $file = new FileModelSaveSpy();
    $file->setRawAttributes(['uuid' => 'file-1'], true);

    $uuidRequest = Request::create('/int/v1/files/file-1', 'PATCH', [
        'subject_uuid' => 'subject-1',
        'subject_type' => 'user',
    ]);

    expect($file->setSubjectFromRequest($uuidRequest))->toBe($file)
        ->and($file->updates)->toHaveCount(2)
        ->and($file->updates[0])->toBe([
            'subject_uuid' => 'subject-1',
            'subject_type' => '\\' . User::class,
        ])
        ->and($file->updates[1])->toBe([
            'subject_type' => '\\' . User::class,
        ]);

    $typeOnlyRequest = Request::create('/int/v1/files/file-1', 'PATCH', [
        'subject_type' => User::class,
    ]);

    $file->setSubjectFromRequest($typeOnlyRequest);

    expect($file->updates)->toHaveCount(3)
        ->and($file->updates[2])->toBe([
            'subject_type' => User::class,
        ]);
});

it('generates random filenames with normalized lowercase extensions', function () {
    $png = File::randomFileName();
    $jpg = File::randomFileName('JPG');
    $gif = File::randomFileName('.GIF');

    expect($png)->toEndWith('.png')
        ->and($jpg)->toEndWith('.jpg')
        ->and($gif)->toEndWith('.gif')
        ->and($png)->not->toBe($jpg);
});
