<?php
/**
 * Lexicon: what customers call things.
 *
 * Two jobs, both about language and neither about medical judgement:
 *  1. Brand names -> active ingredient ("Accutane" -> isotretinoin, "Ozempic" -> semaglutide), typo tolerant,
 *     so a customer who types a brand you do not stock is shown the same ingredient you do list.
 *  2. Concern words -> the other words customers use for it ("pimple", "zits", "breakout" -> acne), so
 *     the catalogue can be searched on what each product page says it is used for.
 *
 * It never maps a condition to a drug. Which product is shown for a concern is decided by the text on your
 * own product pages, not by this list. Owners can add their own lines on Settings -> Shop Assistant -> Smart sales.
 *
 * @package TCCAssistant
 */

defined( 'ABSPATH' ) || exit;

class TCCA_Lexicon {

	/** @var array|null brand (normalised) => generic ("a+b" for combinations) */
	private static $map = null;

	/** @var array|null concern key => array( label, sensitive, phrases ) */
	private static $concerns = null;

	/**
	 * Built-in brand names. One line per active ingredient: "ingredient: brand, brand, ...".
	 * A "+" joins the ingredients of a combination product. Only widely known brands; controlled
	 * substances are deliberately left out (add your own lines if you list them).
	 *
	 * @return string
	 */
	private static function brand_data() {
		return <<<'TXT'
isotretinoin: accutane, roaccutane, absorica, claravis, amnesteem, myorisan, zenatane, sotret
tretinoin: retin-a, retin a, atralin, renova
adapalene: differin
adapalene+benzoyl peroxide: epiduo
dapsone: aczone
minoxidil: rogaine, regaine
finasteride: propecia, proscar
dutasteride: avodart
bimatoprost: latisse, lumigan, careprost
semaglutide: ozempic, wegovy, rybelsus
tirzepatide: mounjaro, zepbound
liraglutide: victoza, saxenda
dulaglutide: trulicity
exenatide: byetta, bydureon
insulin glargine: lantus, toujeo, basaglar
insulin detemir: levemir
insulin degludec: tresiba
insulin aspart: novorapid, novolog
insulin lispro: humalog
insulin glulisine: apidra
human insulin: humulin, actrapid
metformin: glucophage, glumetza, glycomet
sitagliptin: januvia
empagliflozin: jardiance
dapagliflozin: farxiga, forxiga
canagliflozin: invokana
glimepiride: amaryl
pioglitazone: actos
orlistat: xenical, alli
naltrexone+bupropion: contrave
atorvastatin: lipitor
rosuvastatin: crestor
simvastatin: zocor
ezetimibe: zetia
amlodipine: norvasc, amlong
lisinopril: zestril, prinivil
valsartan: diovan
losartan: cozaar
telmisartan: telma
metoprolol: lopressor, toprol
atenolol: tenormin
clopidogrel: plavix
warfarin: coumadin
apixaban: eliquis
rivaroxaban: xarelto
furosemide: lasix
sacubitril+valsartan: entresto
aspirin: ecosprin
sildenafil: viagra, revatio, kamagra
tadalafil: cialis, adcirca
vardenafil: levitra
dapoxetine: priligy
esomeprazole: nexium
omeprazole: prilosec
lansoprazole: prevacid
pantoprazole: protonix, pantop
famotidine: pepcid
ranitidine: zantac
ondansetron: zofran
loperamide: imodium
bisacodyl: dulcolax
ibuprofen: advil, motrin, nurofen
ibuprofen+paracetamol: combiflam
paracetamol: tylenol, panadol, crocin, calpol, dolo
naproxen: aleve
diclofenac: voltaren
celecoxib: celebrex
sertraline: zoloft
fluoxetine: prozac
escitalopram: lexapro, cipralex
citalopram: celexa
bupropion: wellbutrin
venlafaxine: effexor
duloxetine: cymbalta
aripiprazole: abilify
quetiapine: seroquel
olanzapine: zyprexa
salbutamol: ventolin, proair
fluticasone+salmeterol: seretide, advair
budesonide+formoterol: symbicort
fluticasone: flonase
montelukast: singulair
cetirizine: zyrtec
loratadine: claritin
fexofenadine: allegra
diphenhydramine: benadryl
guaifenesin: mucinex
amoxicillin: amoxil
amoxicillin+clavulanate: augmentin
azithromycin: zithromax
ciprofloxacin: cipro
sulfamethoxazole+trimethoprim: bactrim
metronidazole: flagyl
doxycycline: vibramycin
valacyclovir: valtrex
aciclovir: zovirax
oseltamivir: tamiflu
fluconazole: diflucan
terbinafine: lamisil
itraconazole: sporanox
clotrimazole: lotrimin
ketoconazole: nizoral
ivermectin: stromectol
hydroxychloroquine: plaquenil
levothyroxine: synthroid, levoxyl, eltroxin, thyronorm
estradiol: estrace
conjugated estrogens: premarin
clomiphene: clomid
anastrozole: arimidex
letrozole: femara
tamoxifen: nolvadex
cabergoline: dostinex
methylprednisolone: medrol
dexamethasone: decadron
betamethasone: betnovate
botulinum toxin: botox
adalimumab: humira
etanercept: enbrel
infliximab: remicade
ustekinumab: stelara
dupilumab: dupixent
denosumab: prolia
teriparatide: forteo
somatropin: genotropin, norditropin
TXT;
	}

	/**
	 * Built-in concern words. "!" in front marks a concern that is not browsed at all: it is handed to a
	 * pharmacist or doctor (mental health, cancer, epilepsy and the like).
	 *
	 * @return string
	 */
	private static function concern_data() {
		return <<<'TXT'
acne = acne, pimple, pimples, zit, zits, breakout, breakouts, blackhead, blackheads, whitehead, whiteheads, acne vulgaris, cystic acne, acne scars
hair loss = hair loss, hairloss, hair fall, hairfall, hair thinning, thinning hair, alopecia, baldness, receding hairline, male pattern baldness, hair regrowth, bald patches
weight loss = weight loss, lose weight, losing weight, weight management, obesity, obese, overweight, fat loss, belly fat, slimming, weight gain
diabetes = diabetes, diabetic, blood sugar, high sugar, sugar control, glucose control, type 2 diabetes, type 1 diabetes, high glucose
high blood pressure = high blood pressure, hypertension, blood pressure, bp
cholesterol = cholesterol, high cholesterol, triglycerides, lipids
thyroid = thyroid, hypothyroid, hypothyroidism, underactive thyroid, hyperthyroid, hyperthyroidism
erectile dysfunction = erectile dysfunction, impotence, erection problems, erection issues, cant get an erection, trouble with erections
premature ejaculation = premature ejaculation, ejaculating too early, early ejaculation
eczema = eczema, dermatitis, itchy skin, dry skin, atopic dermatitis
psoriasis = psoriasis, scaly patches
dandruff = dandruff, flaky scalp, itchy scalp
rosacea = rosacea, facial redness
pigmentation = pigmentation, dark spots, melasma, dark patches, uneven skin tone, hyperpigmentation
anti ageing = wrinkles, fine lines, anti ageing, anti aging, ageing skin, aging skin
fungal infection = fungal infection, ringworm, athletes foot, athlete's foot, nail fungus, jock itch, yeast infection, thrush, candida
cold sores = cold sores, cold sore, herpes, genital herpes, shingles
warts = warts, wart, verruca
pain = pain, body pain, muscle pain, joint pain, back pain, neck pain, knee pain, arthritis, joint stiffness, inflammation
headache = headache, headaches, migraine, migraines
period pain = period pain, menstrual cramps, period cramps, painful periods
fever = fever, high temperature
cough and cold = cough, common cold, cold and flu, flu, sore throat, runny nose, blocked nose, congestion, sinus, sinusitis
allergy = allergy, allergies, allergic rhinitis, hay fever, hives, itching, sneezing
asthma = asthma, wheezing, breathlessness, copd
acidity = acidity, heartburn, acid reflux, gerd, indigestion, gastritis, stomach acid
constipation = constipation, hard stools
diarrhoea = diarrhoea, diarrhea, loose motions, loose stools
nausea = nausea, vomiting, motion sickness
bloating = bloating, gas, flatulence, ibs, irritable bowel
insomnia = insomnia, cant sleep, trouble sleeping, sleeplessness
infection = bacterial infection, uti, urinary infection, urine infection, ear infection, throat infection, chest infection, skin infection
menopause = menopause, hot flashes, hot flushes, vaginal dryness
pcos = pcos, polycystic ovary, irregular periods
fertility = fertility, infertility, ovulation, trying to conceive, ivf
contraception = contraception, birth control, emergency contraception
dry eyes = dry eyes, eye drops, red eyes, eye irritation
glaucoma = glaucoma, eye pressure
eyelash growth = eyelash growth, longer eyelashes, eyelash
osteoporosis = osteoporosis, weak bones, bone density, calcium deficiency
anaemia = anaemia, anemia, low hemoglobin, low haemoglobin, iron deficiency
vitamin deficiency = vitamin d deficiency, vitamin b12 deficiency, vitamin deficiency, low vitamin d, low b12
growth hormone = growth hormone, short stature, growth hormone deficiency
smoking = quit smoking, stop smoking, nicotine, smoking cessation
memory = memory loss, forgetfulness, brain health, stroke recovery, brain injury
muscle cramps = muscle cramps, leg cramps, muscle spasm
gout = gout, uric acid, high uric acid
prostate = enlarged prostate, bph, frequent urination
kidney = kidney disease, kidney stones, kidney
liver = fatty liver, liver disease, hepatitis
heart failure = heart failure, swollen ankles, water retention, edema, oedema
blood clots = blood clots, blood thinner, dvt
!depression = depression, depressed, low mood, feeling hopeless
!anxiety = anxiety, panic attacks, panic attack, anxious
!epilepsy = epilepsy, seizures, fits
!cancer = cancer, tumour, tumor, chemotherapy, leukaemia, lymphoma
!hiv = hiv, aids
!psychosis = schizophrenia, bipolar, psychosis, hallucinations
!addiction = addiction, opioid withdrawal, alcohol withdrawal, drug dependence
!transplant = organ transplant, transplant rejection
TXT;
	}

	/* ----------------------------------------------------------------- text */

	/**
	 * Lower-case, letters and digits only, single spaces.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function norm( $s ) {
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
		$s = str_replace( array( '’', "'" ), '', $s );
		return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
	}

	/* --------------------------------------------------------------- brands */

	/**
	 * brand (normalised) => generic. Owner lines win over built-in ones.
	 *
	 * @return array<string,string>
	 */
	public static function map() {
		if ( null !== self::$map ) {
			return self::$map;
		}
		$map = array();
		foreach ( preg_split( '/\r\n|\r|\n/', self::brand_data() ) as $line ) {
			$p = explode( ':', $line, 2 );
			if ( 2 !== count( $p ) ) {
				continue;
			}
			$gen = trim( strtolower( $p[0] ) );
			foreach ( explode( ',', $p[1] ) as $b ) {
				$k = self::norm( $b );
				if ( '' !== $k ) {
					$map[ $k ] = $gen;
				}
			}
		}
		// Owner lines: "Brand, Brand2 = ingredient".
		foreach ( preg_split( '/\r\n|\r|\n/', (string) TCCA_Core::get( 'aliases' ) ) as $line ) {
			$p = preg_split( '/\s*(?:=>|=)\s*/', $line, 2 );
			if ( 2 !== count( $p ) || '' === trim( $p[1] ) ) {
				continue;
			}
			$gen = trim( strtolower( $p[1] ) );
			foreach ( explode( ',', $p[0] ) as $b ) {
				$k = self::norm( $b );
				if ( '' !== $k ) {
					$map[ $k ] = $gen;
				}
			}
		}
		// Longest names first so "retin a" is tried before "a".
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		self::$map = $map;
		return $map;
	}

	/** Forget cached lists (tests and settings changes). */
	public static function reset() {
		self::$map      = null;
		self::$concerns = null;
	}

	/**
	 * Brand names mentioned in a message.
	 *
	 * @param string $text Message.
	 * @return array<int,array{brand:string,key:string,generic:string,fuzzy:bool,typed:string}> In order of appearance.
	 */
	public static function find( $text ) {
		$n     = ' ' . self::norm( $text ) . ' ';
		$map   = self::map();
		$found = array();
		foreach ( $map as $b => $g ) {
			$pos = strpos( $n, ' ' . $b . ' ' );
			if ( false === $pos ) {
				continue;
			}
			$found[ $pos ] = array( 'brand' => self::display( $b ), 'key' => $b, 'generic' => $g, 'fuzzy' => false, 'typed' => $b );
			$n             = substr_replace( $n, str_repeat( ' ', strlen( $b ) + 2 ), $pos, strlen( $b ) + 2 ); // Do not match inside it again.
		}
		// Typos: one or two letters off a single-word brand ("acutane" -> accutane). Skips words the shop itself uses.
		$vocab = (array) get_option( 'tcca_vocab', array() );
		$off   = 0;
		foreach ( preg_split( '/\s+/', trim( $n ), -1, PREG_SPLIT_NO_EMPTY ) as $w ) {
			$off = strpos( $n, ' ' . $w . ' ', $off );
			$off = false === $off ? 0 : $off;
			$len = strlen( $w );
			if ( $len < 6 || ctype_digit( $w ) || isset( $vocab[ $w ] ) ) {
				continue;
			}
			$best = '';
			$dist = $len >= 9 ? 3 : 2;
			foreach ( $map as $b => $g ) {
				if ( false !== strpos( $b, ' ' ) || $b[0] !== $w[0] || abs( strlen( $b ) - $len ) > 2 ) {
					continue;
				}
				$d = levenshtein( $w, $b );
				if ( $d < $dist ) {
					$dist = $d;
					$best = $b;
				}
			}
			if ( '' !== $best ) {
				$found[ $off ] = array( 'brand' => self::display( $best ), 'key' => $best, 'generic' => $map[ $best ], 'fuzzy' => true, 'typed' => $w );
			}
		}
		ksort( $found );
		return array_values( $found );
	}

	/**
	 * Show a brand the way people write it.
	 */
	private static function display( $key ) {
		return ucwords( $key );
	}

	/**
	 * Put the ingredient next to every brand name, so every later step (product lookup, compare, discount, add to
	 * cart) can find the matching product without knowing about brands. The brand stays in the text.
	 *
	 * @param string $text  Message.
	 * @param array  $found Result of find().
	 * @return string
	 */
	public static function expand( $text, $found = null ) {
		$found = null === $found ? self::find( $text ) : $found;
		foreach ( $found as $f ) {
			$gen = str_replace( '+', ' ', $f['generic'] );
			if ( false !== stripos( $text, $gen ) ) {
				continue;
			}
			$words = preg_split( '/\s+/', $f['typed'], -1, PREG_SPLIT_NO_EMPTY );
			$re    = '/\b(' . implode( '[\s\-]+', array_map( static function ( $w ) {
				return preg_quote( $w, '/' );
			}, $words ) ) . ')\b/i';
			$text  = preg_replace( $re, '$1 ' . $gen, $text, 1 );
		}
		return $text;
	}

	/**
	 * The separate ingredients of a generic ("fluticasone+salmeterol" -> two).
	 *
	 * @param string $generic Generic as stored.
	 * @return string[]
	 */
	public static function ingredients( $generic ) {
		return array_values( array_filter( array_map( 'trim', explode( '+', strtolower( $generic ) ) ) ) );
	}

	/**
	 * Strings that, found in a product's name or composition, mean "this product contains that ingredient".
	 * "insulin glargine" is also recognised by "glargine" alone, because that is how it is usually listed.
	 *
	 * @param string $ingredient One ingredient.
	 * @return string[]
	 */
	public static function needles( $ingredient ) {
		$ingredient = trim( strtolower( $ingredient ) );
		$out        = array( $ingredient );
		$w          = preg_split( '/\s+/', $ingredient );
		if ( count( $w ) > 1 && 'insulin' === $w[0] ) {
			$out[] = $w[1];
		}
		return $out;
	}

	/**
	 * Does this text contain every ingredient of the generic?
	 *
	 * @param string $hay     Lower-case product name + composition text.
	 * @param string $generic Generic ("a+b").
	 * @return bool
	 */
	public static function contains_generic( $hay, $generic ) {
		$hay = ' ' . self::norm( $hay ) . ' ';
		foreach ( self::ingredients( $generic ) as $ing ) {
			$ok = false;
			foreach ( self::needles( $ing ) as $nd ) {
				if ( false !== strpos( $hay, ' ' . self::norm( $nd ) . ' ' ) || false !== strpos( $hay, self::norm( $nd ) ) ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/* ------------------------------------------------------------- concerns */

	/**
	 * key => array( label, sensitive, phrases ).
	 *
	 * @return array<string,array{label:string,sensitive:bool,phrases:string[]}>
	 */
	public static function concerns() {
		if ( null !== self::$concerns ) {
			return self::$concerns;
		}
		$out = array();
		$add = static function ( $line, $own ) use ( &$out ) {
			$p = explode( '=', $line, 2 );
			if ( 2 !== count( $p ) ) {
				return;
			}
			$sens  = 0 === strpos( trim( $p[0] ), '!' );
			$label = trim( ltrim( trim( $p[0] ), '!' ) );
			$key   = TCCA_Lexicon::norm( $label );
			if ( '' === $key ) {
				return;
			}
			$ph = array( $label );
			foreach ( explode( ',', $p[1] ) as $x ) {
				$x = trim( $x );
				if ( '' !== $x ) {
					$ph[] = $x;
				}
			}
			if ( isset( $out[ $key ] ) ) {
				$out[ $key ]['phrases'] = array_values( array_unique( array_merge( $out[ $key ]['phrases'], $ph ) ) );
				return;
			}
			$out[ $key ] = array( 'label' => $label, 'sensitive' => $sens, 'phrases' => array_values( array_unique( $ph ) ) );
		};
		foreach ( preg_split( '/\r\n|\r|\n/', self::concern_data() ) as $line ) {
			$add( $line, false );
		}
		foreach ( preg_split( '/\r\n|\r|\n/', (string) TCCA_Core::get( 'concerns' ) ) as $line ) {
			$add( $line, true );
		}
		self::$concerns = $out;
		return $out;
	}

	/**
	 * The concern a message is about (longest matching phrase wins).
	 *
	 * @param string $text Message.
	 * @return array|null { key, label, sensitive, phrases, matched }
	 */
	public static function find_concern( $text ) {
		$n    = ' ' . self::norm( $text ) . ' ';
		$best = null;
		$len  = 0;
		foreach ( self::concerns() as $key => $c ) {
			foreach ( $c['phrases'] as $ph ) {
				$k = self::norm( $ph );
				if ( '' === $k || strlen( $k ) <= $len ) {
					continue;
				}
				if ( false !== strpos( $n, ' ' . $k . ' ' ) ) {
					$best = array( 'key' => $key, 'label' => $c['label'], 'sensitive' => $c['sensitive'], 'phrases' => $c['phrases'], 'matched' => $k );
					$len  = strlen( $k );
				}
			}
		}
		return $best;
	}
}
