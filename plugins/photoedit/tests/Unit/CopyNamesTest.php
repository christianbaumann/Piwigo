<?php
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The names of the copies under _data/photoedit/originals/: one per edit or
 * replace, '<id>-<YmdHis>-<microseconds>.<ext>', and the folder a deleted
 * photo's copies move to, 'deleted/<id>-<YmdHis>/' (design of change
 * 2026-10-15-exploratory-testing-answers, "Copies of a deleted photo move aside").
 */
final class CopyNamesTest extends TestCase
{
    /** 2026-10-15 13:04:05 in the server's time zone. */
    private static function time(): int
    {
        return mktime(13, 4, 5, 10, 15, 2026);
    }

    /** [HAPPY] A copy carries the photo's id, the second and the microseconds of the save, and the file's type. */
    public function testACopyIsNamedAfterThePhotoAndTheMoment(): void
    {
        $this->assertSame('42-20261015130405-000123.png', photoedit_copy_name(42, 'png', self::time(), 123));
    }

    /** [BVA] Microseconds keep six digits, so two copies in one second sort by their order. */
    public function testMicrosecondsArePaddedToSixDigits(): void
    {
        $this->assertSame('42-20261015130405-999999.jpg', photoedit_copy_name(42, 'jpg', self::time(), 999999));
        $this->assertSame('42-20261015130405-000000.jpg', photoedit_copy_name(42, 'jpg', self::time(), 0));
    }

    /** [HAPPY] A deleted photo's folder carries its id and the second of the deletion. */
    public function testTheDeletedFolderIsNamedAfterThePhotoAndTheDeletion(): void
    {
        $this->assertSame('deleted/42-20261015130405/', photoedit_deleted_dir_name(42, self::time()));
    }

    public static function names(): array
    {
        return array(
            '[HAPPY] its own copy' => array('42-20261015130405-000123.png', true),
            '[ECP] its own copy of a JPEG' => array('42-20261015130405-000123.jpeg', true),
            '[BVA] a longer id starting with the same digits' => array('421-20261015130405-000123.png', false),
            '[BVA] a shorter id that is a prefix' => array('4-20261015130405-000123.png', false),
            '[NEG] the deleted folder' => array('deleted', false),
            '[NEG] a file that only starts with the id' => array('42-notes.txt', false),
            '[NEG] a copy without microseconds' => array('42-20261015130405.png', false),
            );
    }

    /** Only a name photoedit_copy_name() can produce for this id is that photo's copy. */
    #[DataProvider('names')]
    public function testOnlyThePhotosOwnCopiesAreItsCopies(string $name, bool $expected): void
    {
        $this->assertSame($expected, photoedit_is_copy_of($name, 42));
    }

    /** [HAPPY] Every name the namer produces is recognised as that photo's copy, and as no other's. */
    public function testTheNamerAndTheMatcherAgree(): void
    {
        $name = photoedit_copy_name(7, 'png', self::time(), 5);

        $this->assertTrue(photoedit_is_copy_of($name, 7));
        $this->assertFalse(photoedit_is_copy_of($name, 70));
    }

    /** [HAPPY] exiftool's backup sits beside the file, its name extended by '_original'. */
    public function testTheSidecarSitsBesideTheFile(): void
    {
        $this->assertSame('galleries/a/b.png_original', photoedit_sidecar_path('galleries/a/b.png'));
    }
}
