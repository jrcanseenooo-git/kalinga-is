<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Kalinga Vulnerability Assessment Tool (KaVAT), as written in the Enhanced
 * Pilot Guidelines (Kalinga), pages 1-10.
 *
 * Part 1 (25 statements, 7 domains) measures empowerment: Oo / Hindi
 * Sigurado / Hindi score 5 / 3 / 1, reversed (1 / 3 / 5) for items 4, 5, 9,
 * 14, 18, 22 and 24. Range 25-125.
 * Part 2 (9 sections) measures objective risk with the points in the
 * guidelines. Range 0-98. A refusal to answer is a red flag; in sections
 * 6-9 it scores the maximum.
 *
 * Empowerment rate = Part 1 / 125 x 100; objective risk rate = Part 2 / 98 x
 * 100; safety rate = 100 - objective risk rate; final protection score =
 * empowerment x 0.40 + safety x 0.60. Below 70 = High Priority (Eligible).
 *
 * OWNER REVIEW (guideline issues, formulas applied as written):
 * - section 6 lists a maximum of 10 but its two questions can reach 15; the
 *   Part 2 total of 98 only adds up with 15, so 15 is used;
 * - the worked example writes 54 x 0.40 for an empowerment rate of 52 (its
 *   result, 26.92, is correct with 52);
 * - sections 6 and 7 have no "not applicable" choice for a child without a partner.
 *
 * The same definition is sent to the browser, which scores with it while the
 * case manager answers (resources/js/Casework/kavat.ts); the server score is
 * the one saved.
 */
final class Kavat
{
    public const VERSION = '2026-pilot';

    public const PART1_MAX = 125;

    public const PART2_MAX = 98;

    public const PRIORITY_THRESHOLD = 70;

    /**
     * The KaVAT is administered three times: Baseline (done before intake in the
     * project's separate tool; only its result is recorded, kavat_baselines),
     * Midline and Endline (answered in Kalinga, one of each per case).
     */
    public const OCCASIONS = ['midline' => 'Midline KaVAT', 'endline' => 'Endline KaVAT'];

    public const OCCASION_LABELS = self::OCCASIONS + ['baseline' => 'Baseline', 'quarterly' => 'Quarterly KaVAT', 'closure' => 'At closure', 'review' => 'Review', 'other' => 'Other'];

    private const ANSWERS = ['yes' => 'Oo (Yes)', 'maybe' => 'Hindi Sigurado (Maybe)', 'no' => 'Hindi (No)'];

    private const REVERSED = [4, 5, 9, 14, 18, 22, 24];

    private const STATEMENTS = [
        1 => 'Kaya kong ipaliwanag ang aking mga karapatan bilang isang bata, kabilang ang aking karapatang maging ligtas mula sa child, early and forced marriage/union.',
        2 => 'Alam ko na may mga batas at polisiya na nagpoprotekta sa mga bata laban sa child, early and forced marriage/union at iba pang uri ng pang-aabuso o pagsasamantala.',
        3 => 'Alam ko kung saan at kanino ako maaaring lumapit kung may lumalabag sa aking mga karapatan o kung hindi ako ligtas.',
        4 => 'Pakiramdam ko ay wala akong kakayahan na magsabi ng aking saloobin at gumawa ng mga desisyon tungkol sa mga bagay na may epekto sa aking buhay.',
        5 => 'Nakakaramdam ako na hindi ligtas sa aking tahanan, sa aking relasyon o pagsasama, at sa aking komunidad.',
        6 => 'Ako ay may ganap na kalayaan at kontrol sa aking mga desisyon, nang walang anumang pamimilit, pagbabanta, o pagtatangkang kumontrol mula sa ibang tao.',
        7 => 'Kapag may hindi magandang nangyayari sa akin, may isang taong mapagkakatiwalaan kong malalapitan para sa tulong at proteksyon.',
        8 => 'Alam ko kung paano humingi ng tulong kung ako ay nakaranas o nanganganib na makaranas ng pananakit, pang-aabuso, pagbabanta, o sapilitang pagsasama.',
        9 => 'Hindi ko malayang naipapahayag ang aking kagustuhan tungkol sa pakikipagrelasyon, pagsasama, o pagpapakasal.',
        10 => 'Nakakapagdesisyon ako tungkol sa aking pakikipag-relasyon o pagsasama nang walang pamimilit o matinding pressure mula sa ibang tao.',
        11 => 'Pakiramdam ko ay pantay ang aking boses at kakayahang magdesisyon sa aking relasyon o pagsasama.',
        12 => 'May mga pagkakataong hindi ko magawa ang gusto ko o hindi ako makapagdesisyon para sa aking sarili dahil sa aking relasyon o pagsasama.',
        13 => 'Nakakakuha ako ng tamang impormasyon tungkol sa aking kalusugan, sexual and reproductive health, at mga paraan upang mapangalagaan ang aking sarili.',
        14 => 'Hindi madali para sa akin ang pag-access/paglapit sa libreng health services at counseling o pakikipag-usap sa propesyonal or eksperto sa mental health na angkop sa aking edad at pangangailangan.',
        15 => 'Alam ko kung saan maaaring humingi ng tulong para sa mga alalahanin tungkol sa aking pisikal, sexual, at reproductive health.',
        16 => 'Nahihiya o natatakot akong humingi ng tulong o magtanong tungkol sa aking kalusugan at sexual and reproductive health.',
        17 => 'Kasalukuyan akong nakakapag-aral o may malinaw na pagkakataong maipagpatuloy ang aking edukasyon o pagsasanay.',
        18 => 'Ang aking relasyon, pagsasama, o mga responsibilidad sa bahay ay hindi nakahahadlang sa pagpapatuloy ng aking pag-aaral o pagsasanay.',
        19 => 'May access ako sa mga programa o oportunidad na makatutulong sa akin na magkaroon ng mga kasanayan para sa trabaho o kabuhayan sa hinaharap.',
        20 => 'May sapat akong pinansyal na suporta upang matustusan ang aking mga pangunahing pangangailangan at maipagpatuloy ang aking pag-aaral o iba pang plano para sa hinaharap.',
        21 => 'May mga miyembro ng aking pamilya o ibang taong malapit sa akin na sumusuporta at gumagalang sa aking mga desisyon at kapakanan.',
        22 => 'Hindi ako nakakaranas ng panghuhusga, paninisi, diskriminasyon, o negatibong pagtrato dahil sa aking relasyon, pagsasama, o sitwasyon.',
        23 => 'May access ako sa mga serbisyo at programa sa aking komunidad na maaaring makatulong sa aking sitwasyon.',
        24 => 'Madalas akong nakakaramdam ng takot, matinding pag-aalala, lungkot, o pagkalito tungkol sa aking kasalukuyang sitwasyon at kinabukasan.',
        25 => 'Naniniwala akong may mga pagkakataon at suporta upang makamit ko ang mga plano at pangarap ko para sa aking kinabukasan.',
    ];

    /** [title, items, [low band max, middle band max], [low, middle, high interpretation]] */
    private const DOMAINS = [
        'I' => ["Child's Rights, Legal Awareness, and Empowerment", [1, 4], [12, 16], ['Limitado ang kaalaman, posibleng vulnerable sa abuse o exploitation.', 'May kaalaman ngunit kailangan pa ng pagpapalakas.', 'Malinaw na nauunawaan at naipapakita ang empowerment at karapatan.']],
        'II' => ['Safety, Protection, and Freedom from Violence', [5, 8], [12, 16], ['Kinakailangan ng masinsinang counseling at guidance / mataas ang panganib.', 'May kaalaman ngunit kailangan pa ng dagdag na suporta.', 'Malinaw ang kaalaman at kakayahan sa pag-iwas at paghingi ng proteksyon.']],
        'III' => ['Relationship, Marriage/Union, and Decision-Making', [9, 12], [12, 16], ['Limitado ang kakayahang magdesisyon at malayang magpahayag ng kagustuhan.', 'May kakayahang magdesisyon ngunit may kaunting pressure o limitasyon.', 'Malaya at pantay na nakapagdedesisyon sa relasyon nang walang pamimilit.']],
        'IV' => ['Health, Sexual and Reproductive Health', [13, 16], [12, 16], ['Mataas ang panganib sa kalusugan, nahihirapang mag-access ng libreng health services.', 'May potensyal ngunit kailangan pang palakasin ang kaalaman at access.', 'Aktibo at mahusay sa paggamit ng health services at gawi para sa kalusugan.']],
        'V' => ['Education, Skills, and Economic Empowerment', [17, 20], [12, 16], ['Posibleng walang malinaw na plano, kinakailangan ng mentoring at suporta.', 'Nangangailangan ng guidance at access sa opportunities.', 'May malinaw na plano, suporta, at motibasyon para sa edukasyon at kabuhayan.']],
        'VI' => ['Family and Community Support', [21, 23], [8, 12], ['Kulang ang suporta, maaaring nakararanas ng diskriminasyon o isolation.', 'May suporta ngunit hindi laging sapat.', 'Malakas ang suporta mula sa pamilya at komunidad.']],
        'VII' => ['Psychosocial Well-being and Future Outlook', [24, 25], [5, 8], ['Mababa ang kakayahang i-regulate ang emosyon, nakakaramdam ng matinding takot/alalahanin.', 'Katamtaman ang emotional stability, may pangangailangan pa sa psychosocial support.', 'Maayos ang pag-iisip, matatag sa emosyon, at may positibong pananaw sa hinaharap.']],
    ];

    private const BANDS = ['Mababa (Low)', 'Katamtaman (Moderate)', 'Mataas (High)'];

    /**
     * Part 2: section => [title, sensitive, questions]; question => [key, text, options, max];
     * option => [code, label, points, detail prompt or null].
     */
    private const PART2 = [
        1 => ['Living Arrangement', false, [['living_arrangement', 'Sino ang kasalukuyan mong kasama sa bahay?', [
            ['1', 'Child has no living parent and no stable primary caregiver / orphan', 10, null],
            ['2', 'Living with a guardian/relative and neither parent provides regular support', 7, null],
            ['3', "Living with a partner or partner's family", 5, null],
            ['4', 'Living with at least one parent who provides regular support', 0, null],
            ['rta', 'Refused to Answer', 5, null]], 10]]],
        2 => ['Parental/Family Support', false, [['family_support', 'May regular ka bang natatanggap na suporta mula sa iyong magulang o pamilya para sa iyong pangangailangan, pag-aaral, kalusugan, at iba pang pangangailangan?', [
            ['1', 'No meaningful family support', 10, null],
            ['2', 'Irregular/limited support', 5, null],
            ['3', 'Adequate regular support', 0, null],
            ['rta', 'Refused to Answer', 5, null]], 10]]],
        3 => ['Presence of Children/Dependent', false, [['dependents', 'Ikaw ba ay may anak o iba pang taong ikaw ang pangunahing nag-aalaga o sumusuporta?', [
            ['1', 'The child has a child/dependent and is the primary caregiver', 10, null],
            ['2', 'With child/dependent and with shared caregiving responsibility', 5, null],
            ['3', 'No child/dependent', 0, null],
            ['rta', 'Refused to Answer', 5, null]], 10]]],
        4 => ['Current Education Status', false, [['education', 'Kasalukuyan ka bang nag-aaral o sumasailalim sa anumang education/training program?', [
            ['1', 'Out of school and not enrolled in any alternative education/training', 10, null],
            ['2', 'Temporarily stopped from schooling/training for the current school year', 7, null],
            ['3', 'Enrolled but irregular attendance', 4, null],
            ['4', 'Regularly attending', 0, 'Type of education'],
            ['rta', 'Refused to Answer', 5, null]], 10]]],
        5 => ['Personal/Household Economic Support', false, [
            ['economic_support', 'May regular bang pinagkukunan ng pera para sa iyong pangunahing pangangailangan?', [
                ['1', 'No reliable financial support', 5, null],
                ['2', 'Irregular/inadequate support', 3, null],
                ['3', 'Adequate and reliable support', 0, null],
                ['rta', 'Refused to Answer', 2.5, null]], 5],
            ['breadwinner', 'Ikaw ba ay breadwinner sa pamilya?', [
                ['yes', 'Yes / Oo', 5, 'Since when'],
                ['no', 'No / Hindi', 0, null],
                ['rta', 'Refused to Answer', 2.5, null]], 5],
            ['income', 'May pinagkukunan ka ba ng kita sa kasalukuyan?', [
                ['yes', 'Yes / Oo', 3, 'Specify'],
                ['no', 'No / Hindi', 0, null],
                ['rta', 'Refused to Answer', 1.5, null]], 3]]],
        6 => ['Age Difference Between Child and Partner', true, [
            ['partner_age_gap', 'Ilang taon ang iyong partner/asawa/ka-union?', [
                ['1', 'Partner is 10 years older or more', 10, null],
                ['2', '5-9 years older', 7, null],
                ['3', '1-4 years older', 3, null],
                ['4', 'Same age', 1, null],
                ['5', 'Partner is younger', 3, null],
                ['rta', 'Refused to Answer', 10, null]], 10],
            ['age_at_union', 'Ilang taon ka noong nagsimula ang inyong relasyon o pagsasama?', [
                ['minor', 'Minor', 5, 'Age'],
                ['adult', 'Adult', 0, 'Age'],
                ['rta', 'Refused to Answer', 5, null]], 5]]],
        7 => ["Child's Participation in the Decision to Enter/Continue the Union", true, [['decision', 'Nakapagdesisyon ka ba nang malaya tungkol sa pagsisimula o pagpapatuloy ng inyong relasyon/pagsasama?', [
            ['1', 'Child reports no meaningful choice / was forced', 10, null],
            ['2', 'There was strong pressure from family/partner/community', 7, null],
            ['3', 'Decision was influenced but child had some choice', 3, null],
            ['4', 'Child reports meaningful participation in the decision', 0, null],
            ['rta', 'Refused to Answer', 10, null]], 10]]],
        8 => ['History or Current Experience of Violence/Abuse', true, [['violence', 'Naranasan mo na ba o kasalukuyan ka bang nakakaranas ng pananakit, sexual abuse, emotional/psychological abuse, pagbabanta, o iba pang anyo ng karahasan?', [
            ['yes', 'Yes / Oo', 10, 'What'],
            ['no', 'No / Hindi', 0, null],
            ['rta', 'Refused to Answer', 10, null]], 10]]],
        9 => ['Current Safety Concern', true, [['safety', 'Sa kasalukuyan, nararamdaman mo ba na hindi ka ligtas sa iyong tahanan, relasyon/pagsasama, o komunidad?', [
            ['yes', 'Yes / Oo', 10, null],
            ['no', 'No / Hindi', 0, null],
            ['rta', 'Refused to Answer', 10, null]], 10]]],
    ];

    /** The instrument for the browser (questions, options, points, bands). */
    /** Final protection score from the two rates (the guideline formula). */
    public static function finalScore(float $empowerment, float $safety): float
    {
        return round($empowerment * 0.4 + $safety * 0.6, 2);
    }

    public static function priorityFor(float $finalScore): string
    {
        return $finalScore < self::PRIORITY_THRESHOLD ? 'high' : 'standard';
    }

    public static function definition(): array
    {
        $domains = [];
        foreach (self::DOMAINS as $code => [$title, [$from, $to], $bands, $texts]) {
            $domains[] = ['code' => $code, 'title' => $title, 'items' => range($from, $to), 'bands' => $bands, 'interpretations' => $texts,
                'statements' => array_map(fn ($n) => ['number' => $n, 'text' => self::STATEMENTS[$n], 'reversed' => in_array($n, self::REVERSED, true)], range($from, $to))];
        }
        $sections = [];
        foreach (self::PART2 as $number => [$title, $sensitive, $questions]) {
            $sections[] = ['number' => $number, 'title' => $title, 'sensitive' => $sensitive, 'questions' => array_map(fn ($q) => [
                'key' => $q[0], 'text' => $q[1], 'max' => $q[3],
                'options' => array_map(fn ($o) => ['code' => $o[0], 'label' => $o[1], 'points' => $o[2], 'detail' => $o[3]], $q[2]),
            ], $questions)];
        }

        return ['version' => self::VERSION, 'answers' => self::ANSWERS, 'bandLabels' => self::BANDS, 'domains' => $domains, 'sections' => $sections,
            'part1Max' => self::PART1_MAX, 'part2Max' => self::PART2_MAX, 'threshold' => self::PRIORITY_THRESHOLD, 'occasions' => self::OCCASION_LABELS, 'startOccasions' => array_keys(self::OCCASIONS)];
    }

    /**
     * Validates and normalizes answers from the page or from field mode.
     *
     * @return array{part1: object, part2: object, details: object}
     */
    public static function cleanAnswers(mixed $answers): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['answers' => $message]);
        if (!is_array($answers) || !is_array($answers['part1'] ?? []) || !is_array($answers['part2'] ?? []) || !is_array($answers['details'] ?? [])) {
            $fail('The answers are not valid.');
        }
        $part1 = [];
        foreach ($answers['part1'] ?? [] as $number => $value) {
            if (!ctype_digit((string) $number) || $number < 1 || $number > 25 || !in_array($value, ['yes', 'maybe', 'no'], true)) {
                $fail('Part 1 has statements 1 to 25 answered Oo, Hindi Sigurado or Hindi.');
            }
            $part1[(string) $number] = $value;
        }
        $part2 = [];
        foreach ($answers['part2'] ?? [] as $key => $value) {
            if (!in_array($value, self::part2Options((string) $key), true)) {
                $fail('An answer in Part 2 is not one of the choices.');
            }
            $part2[$key] = $value;
        }
        $details = [];
        foreach ($answers['details'] ?? [] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!in_array($key, self::part2Keys(), true) || !is_string($value) || mb_strlen($value) > 255) {
                $fail('A Part 2 detail is not valid.');
            }
            $details[$key] = $value;
        }

        return ['part1' => (object) $part1, 'part2' => (object) $part2, 'details' => (object) $details];
    }

    /** Every Part 2 question key. */
    public static function part2Keys(): array
    {
        $keys = [];
        foreach (self::PART2 as [, , $questions]) {
            foreach ($questions as $question) {
                $keys[] = $question[0];
            }
        }

        return $keys;
    }

    /** Allowed answer codes per Part 2 question. */
    public static function part2Options(string $key): array
    {
        foreach (self::PART2 as [, , $questions]) {
            foreach ($questions as $question) {
                if ($question[0] === $key) {
                    return array_column($question[2], 0);
                }
            }
        }

        return [];
    }

    /**
     * Scores whatever has been answered. Complete only when all 25 statements
     * and all Part 2 questions are answered.
     *
     * @param  array{part1?: array<int|string, string>, part2?: array<string, string>}  $answers
     */
    public static function score(array $answers): array
    {
        $part1 = $answers['part1'] ?? [];
        $part2 = $answers['part2'] ?? [];
        $points = fn (int $n, ?string $answer) => match ($answer) {
            'yes' => in_array($n, self::REVERSED, true) ? 1 : 5,
            'maybe' => 3,
            'no' => in_array($n, self::REVERSED, true) ? 5 : 1,
            default => null,
        };

        $p1 = 0;
        $answered1 = 0;
        $domains = [];
        foreach (self::DOMAINS as $code => [$title, [$from, $to], [$low, $middle], $texts]) {
            $sum = 0;
            $complete = true;
            foreach (range($from, $to) as $n) {
                $value = $points($n, $part1[$n] ?? $part1[(string) $n] ?? null);
                if ($value === null) {
                    $complete = false;

                    continue;
                }
                $sum += $value;
                $answered1++;
            }
            $p1 += $sum;
            $band = !$complete ? null : ($sum <= $low ? 0 : ($sum <= $middle ? 1 : 2));
            $domains[] = ['code' => $code, 'title' => $title, 'score' => $sum, 'max' => ($to - $from + 1) * 5, 'complete' => $complete,
                'band' => $band === null ? null : self::BANDS[$band], 'level' => $band, 'interpretation' => $band === null ? null : $texts[$band]];
        }

        $p2 = 0.0;
        $answered2 = 0;
        $sections = [];
        $flags = [];
        foreach (self::PART2 as $number => [$title, $sensitive, $questions]) {
            $sum = 0.0;
            foreach ($questions as [$key, $text, $options, $max]) {
                $choice = $part2[$key] ?? null;
                $option = collect($options)->firstWhere(0, $choice);
                if (!$option) {
                    continue;
                }
                $answered2++;
                $sum += $option[2];
                if ($choice === 'rta') {
                    $flags[] = ['section' => $number, 'title' => $title, 'question' => $key, 'sensitive' => $sensitive];
                }
            }
            $p2 += $sum;
            $sections[] = ['number' => $number, 'title' => $title, 'score' => $sum, 'max' => array_sum(array_column($questions, 3))];
        }

        $complete = $answered1 === 25 && $answered2 === count(self::part2Keys());
        $empowerment = round($p1 / self::PART1_MAX * 100, 2);
        $risk = round($p2 / self::PART2_MAX * 100, 2);
        $safety = round(100 - $risk, 2);
        $final = round($empowerment * 0.40 + $safety * 0.60, 2);

        return [
            'complete' => $complete, 'answered' => $answered1 + $answered2, 'questions' => 25 + count(self::part2Keys()),
            'part1' => $p1, 'part2' => $p2, 'empowerment_rate' => $empowerment, 'objective_risk_rate' => $risk, 'safety_rate' => $safety,
            'final_score' => $complete ? $final : null,
            'priority' => $complete ? ($final < self::PRIORITY_THRESHOLD ? 'high' : 'standard') : null,
            'domains' => $domains, 'sections' => $sections, 'red_flags' => $flags,
        ];
    }

    public static function priorityLabel(?string $priority): ?string
    {
        return ['high' => 'High Priority (Eligible)', 'standard' => 'Standard / Low Priority'][$priority] ?? null;
    }
}
