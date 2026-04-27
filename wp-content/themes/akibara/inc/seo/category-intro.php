<?php
/**
 * Akibara — Intro copy SEO para categorías shop.
 *
 * Resuelve el problema de "thin content" detectado en GSC (940 páginas
 * "Rastreada sin indexar"). Los archives WC mostraban solo H1 + grid sin
 * texto editorial → Google los descartaba como bajo valor.
 *
 * Copy curado por categoría con keywords primarias/secundarias detectadas
 * en GSC Performance (28d) + variantes long-tail típicas del nicho manga.
 *
 * @package Akibara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Devuelve el HTML del intro copy para una categoría/brand/shop dada.
 * Devuelve string vacío si no hay copy curado (no thin a propósito —
 * mejor sin intro que un intro genérico).
 */
function akibara_get_category_intro_html( ?WP_Term $term, string $context = 'category' ): string {
	if ( $context === 'shop' ) {
		$key = 'shop';
	} elseif ( $term instanceof WP_Term ) {
		$key = $term->slug;
	} else {
		return '';
	}

	$copy = akibara_category_intro_map();
	if ( ! isset( $copy[ $key ] ) ) return '';

	$body = $copy[ $key ];
	if ( ! is_string( $body ) || $body === '' ) return '';

	return '<div class="category-intro" itemprop="description">' . $body . '</div>';
}

/**
 * Mapa curado: slug → HTML del intro (200-300 palabras óptimo).
 * Cada entrada incluye keyword primaria, sinónimos comunes,
 * y mención de editoriales/series para contexto semántico.
 */
function akibara_category_intro_map(): array {
	return [

		// Shop principal — captura "mangas chile", "venta de mangas", "manga online chile"
		'shop' => '<p>Bienvenido al catálogo completo de <strong>Akibara</strong>, tu tienda de manga y cómics en Chile. Ofrecemos más de 1.300 títulos originales en español, importados directamente desde España y Argentina. Encontrarás los grandes shōnen como <em>One Piece</em>, <em>Jujutsu Kaisen</em>, <em>Naruto</em> y <em>Demon Slayer</em>; clásicos seinen como <em>Berserk</em>, <em>Vagabond</em> y <em>20th Century Boys</em>; manhwas, comics americanos, ediciones deluxe y kanzenban.</p><p>Trabajamos con las principales editoriales del mundo hispanohablante: Ivrea Argentina, Panini Manga, Planeta Cómic, Milky Way Ediciones, Arechi Manga y Ovni Press. Despachamos a todo Chile con seguimiento y aceptamos pago en hasta 3 cuotas sin interés con Mercado Pago.</p>',

		// Manga — top-level
		'manga' => '<p>Encuentra <strong>manga original en Chile</strong> en su edición oficial en español. Akibara distribuye más de 1.300 títulos de las editoriales líderes del mercado hispanohablante: Ivrea Argentina, Panini Argentina, Planeta España, Milky Way, Arechi Manga y Ovni Press. Cubrimos todas las demografías: shōnen para acción y aventura (<em>One Piece</em>, <em>Jujutsu Kaisen</em>, <em>Demon Slayer</em>), seinen para lectores adultos (<em>Berserk</em>, <em>Vagabond</em>, <em>Monster</em>), shōjo y josei para romance, y manhwa coreano traducido.</p><p>Todos nuestros mangas son <strong>ediciones oficiales</strong> en español neutro o castellano, importadas directo desde el país editor. Sin escaneos, sin piratería, sin traducciones automáticas. Despacho a todo Chile con cuotas sin interés.</p>',

		// Demografías manga
		'shonen' => '<p>Manga <strong>shōnen</strong> en Chile — la demografía más popular del manga, dirigida originalmente a adolescentes pero leída por todas las edades. Aventura, acción, amistad y superación son los pilares de este género. En Akibara encontrarás los grandes shōnen actuales: <em>One Piece</em>, <em>Jujutsu Kaisen</em>, <em>Demon Slayer</em>, <em>My Hero Academia</em>, <em>Black Clover</em>, <em>Chainsaw Man</em>, <em>Dan Da Dan</em>, <em>Blue Lock</em> y <em>Spy x Family</em>; junto a clásicos como <em>Naruto</em>, <em>Bleach</em> y <em>Hunter x Hunter</em>. Edición oficial en español, importada de Ivrea, Panini y Planeta.</p>',

		'seinen' => '<p>Manga <strong>seinen</strong> en Chile — manga para adultos, con narrativa madura, complejidad psicológica y temáticas que van más allá del shōnen tradicional. Si buscas <strong>manga para adultos</strong> con peso narrativo, este es tu género. Encontrarás obras maestras como <em>Berserk</em> de Kentaro Miura, <em>Vagabond</em> de Takehiko Inoue, <em>Monster</em> y <em>20th Century Boys</em> de Naoki Urasawa, <em>Vinland Saga</em>, <em>Kingdom</em>, <em>Dorohedoro</em>, <em>Pluto</em> y <em>Made in Abyss</em>. Ediciones deluxe, kanzenban y maximum disponibles. Importadas oficialmente desde España y Argentina.</p>',

		'shojo' => '<p>Manga <strong>shōjo</strong> en Chile — manga dirigido originalmente a chicas jóvenes, con foco en romance, vínculos emocionales y crecimiento personal. Una demografía con joyas atemporales y nuevas obras que están redefiniendo el género. En Akibara encontrarás títulos como <em>Nana</em>, <em>El Chico que me Gusta no es un Chico</em>, <em>El Verano en que Hikaru Murió</em>, <em>Banana Fish</em> y <em>La Nobleza de las Flores</em>. Ediciones oficiales en español de Ivrea, Panini y Planeta.</p>',

		'manhwa' => '<p><strong>Manhwa</strong> en Chile — el cómic coreano que revolucionó la industria con sus webtoons y ediciones físicas a color. En Akibara encontrarás manhwa traducido oficialmente al español, con títulos populares y nuevas licencias importadas desde España y Argentina. Si buscas <strong>tienda manhwa</strong> en Chile o leer manhwa online en español, nuestro catálogo combina los grandes éxitos del género con joyas menos conocidas. Ediciones físicas, sin piratería, con despacho a todo Chile.</p>',

		'josei' => '<p>Manga <strong>josei</strong> en Chile — dirigido a mujeres adultas, con tramas más realistas y maduras que el shōjo. Romance complejo, drama social y vida cotidiana son sus marcas. Una demografía con menos títulos publicados en español pero gran calidad. Akibara importa los josei licenciados oficialmente por Ivrea, Panini y Planeta, con despacho a todo Chile.</p>',

		'kodomo' => '<p>Manga <strong>kodomo</strong> — manga para niños y niñas. Historias de aventura simple, valores positivos, humor amable. Ideal para iniciar a los más chicos en la lectura de manga. En Akibara encontrarás títulos clásicos kodomo importados oficialmente desde España y Argentina. Edición en español neutro, despacho a todo Chile.</p>',

		'isekai' => '<p>Manga <strong>isekai</strong> en Chile — el subgénero que domina el mercado actual: protagonistas transportados o reencarnados a otro mundo (generalmente fantástico). Mecánicas RPG, sistemas de niveles y aventuras épicas. En Akibara encontrarás isekais como <em>Re:Zero</em>, <em>Mushoku Tensei</em>, <em>Frieren</em>, <em>Shangri-La Frontier</em>, <em>Made in Abyss</em> y más. Importación oficial desde Argentina y España.</p>',

		// Comics
		'comics' => '<p><strong>Cómics en Chile</strong> — Akibara importa cómics originales en español de las grandes editoriales: DC, Vertigo, Marvel, Image y editoriales europeas. Encontrarás novelas gráficas, integrales, sagas completas y obras independientes. Desde <em>Saga</em> y <em>Pluto</em> hasta clásicos de Vertigo como <em>Predicador</em> y <em>From Hell</em>. Trabajamos principalmente con Planeta Cómic España. Despacho a todo Chile con cuotas sin interés.</p>',

		// Preventas (categoría especial)
		'preventas' => '<p><strong>Preventas de manga y cómics en Chile</strong> — reserva tu manga antes del lanzamiento y asegura tu ejemplar. Las tiradas oficiales de manga en español son limitadas y los tomos populares se agotan rápido. Reservar en preventa garantiza precio fijo y entrega cuando el título llegue a Chile (generalmente 4-8 semanas tras lanzamiento internacional). Trabajamos preventas de Ivrea Argentina, Panini, Planeta España, Milky Way y Arechi Manga.</p>',

		// Pedidos especiales
		'pedidos-especiales' => '<p><strong>Pedidos especiales</strong> — encarga títulos que no están en nuestro catálogo regular. Si buscas un manga descatalogado, una edición específica o un tomo importado de un país no listado, te ayudamos a conseguirlo. El proceso toma 2-6 semanas según disponibilidad y país de origen. Sin compromiso de compra hasta confirmar disponibilidad y precio.</p>',

	];
}
