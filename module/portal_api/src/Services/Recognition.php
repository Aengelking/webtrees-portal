<?php

declare(strict_types=1);

namespace Engelking\Webtrees\PortalApi\Services;

use Engelking\Webtrees\PortalApi\PortalApiModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;

use function trim;

/**
 * What is left of a person when their record is closed to the reader.
 *
 * A card in the directory or a connection request names somebody from the
 * portal's own profile — consent data, not genealogy (§2.7) — and everything
 * else on it comes from the record. For a living member outside the reader's
 * few steps of the tree that record is withheld entirely (§2.25,
 * `RecordPresenter::individualRef()` returns null), and the card was a name
 * and white space: no face, no archive number, nothing to recognise anybody
 * by. That is right for the tree and wrong for an address book.
 *
 * So two things may cross that line, and no more. Each has its own reason and
 * neither is a general loosening of webtrees' privacy.
 *
 * **A photograph the person uploaded here themselves.** The permission is
 * theirs, it was given for this portal, and §2.50's rule already says a living
 * person's picture appears only where they put it. `portal_photo` *is* that
 * permission; withholding it because webtrees hides the record it hangs on
 * would be honouring a rule about the family's data against the person the
 * data is about.
 *
 * **The archive number, if the family says so.** Off by default, and a switch
 * in the control panel rather than a per-member choice, because the number is
 * the family's naming scheme rather than anybody's personal data — it comes
 * off a letterhead. Note what already follows from §2.57: any member may
 * *type* a number and reach the person it belongs to, whether or not they may
 * read the record. Showing it is a smaller step than it looks: it makes
 * legible what is already searchable.
 *
 * **A number the record marks confidential is still withheld.** `Fact::canShow()`
 * asks about the `RESN` on the fact rather than the privacy of the record
 * around it, which is exactly the half that belongs here — the same split
 * `Connections::memberByReference()` already relies on.
 *
 * Nothing else. Not the name from the record, not the years, not the
 * relationship: those are the archive's account of a person, and the archive
 * has said no.
 */
class Recognition
{
    public function __construct(
        private readonly PortalApiModule $module,
        private readonly PortalTreeService $trees,
        private readonly PhotoPresenter $photos,
        private readonly SackNumbers $sack_numbers,
    ) {
    }

    /** Whether the family publishes archive numbers to readers who may not see the record. */
    public function numbersShown(): bool
    {
        return $this->module->getPreference(PortalApiModule::SETTING_MEMBER_SHOW_NUMBER, '0') === '1';
    }

    /**
     * What this reader may be shown of `$subject`, whose record they may not
     * read.
     *
     * Only ever called where the record came back null, so there is no second
     * copy of anything: where the record *is* readable, both of these live
     * inside it and are answered by its own rules.
     *
     * @return array{portrait:array<string,mixed>|null,references:array<int,array<string,string|null>>}
     */
    public function of(UserInterface $subject, int $access_level): array
    {
        $tree = $this->trees->tree();

        return [
            'portrait'   => $this->photos->consentedPortrait($subject, $tree),
            'references' => $this->numbersShown() ? $this->references($subject, $tree, $access_level) : [],
        ];
    }

    /**
     * The archive numbers on the subject's record.
     *
     * Read at `PRIV_HIDE` and then filtered fact by fact, for the reason
     * `Connections::memberByReference()` gives at length: `facts()` hands back
     * nothing at all for a record the reader may not see, so reading at their
     * own level would answer "no numbers" for precisely the people this exists
     * for.
     *
     * @return array<int,array<string,string|null>>
     */
    private function references(UserInterface $subject, Tree $tree, int $access_level): array
    {
        $individual = $this->trees->linkedIndividual($tree, $subject);

        if (!$individual instanceof Individual) {
            return [];
        }

        $references = [];

        foreach ($individual->facts(['REFN'], false, Auth::PRIV_HIDE) as $fact) {
            if (!$fact->canShow($access_level)) {
                continue;
            }

            $number = trim($fact->value());

            if ($number === '') {
                continue;
            }

            $type = trim($fact->attribute('TYPE'));

            // The same shape `RecordPresenter::references()` publishes, branch
            // and all: the card that reads it does not know or care which of
            // the two built it, and one of them being a field short is how a
            // shape drifts into two shapes.
            $references[] = [
                'number' => $number,
                'type'   => $type === '' ? null : $type,
                'branch' => $this->sack_numbers->branch($number),
            ];
        }

        return $references;
    }
}
