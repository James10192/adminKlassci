// Régénère public/css/klassci-admin-utilities.css.
//
// Filament ne compile que les classes Tailwind de ses propres vues. Nos vues
// Blade (pages Réglages des établissements, santé, portail groupe) emploient
// des classes qu'il n'a pas (h-12, grid-cols-3, mb-6…) : sans elles, les
// icônes s'étalaient sur toute la page et les grilles s'empilaient.
//
// On ne compile QUE les classes absentes de la feuille de Filament. Recompiler
// « flex » ou « hidden », chargées après sa feuille, écraserait ses variantes
// responsives (« md:hidden ») sur les écrans du panneau.
//
// Usage, depuis la racine du dépôt (Node 18+ et pnpm, rien à installer) :
//   node scripts/utilitaires-admin/generer.mjs
// Rien à lancer sur le serveur : le CSS produit est committé.
//
//   node scripts/utilitaires-admin/generer.mjs --verifier
// échoue si les vues emploient des classes que la feuille ne couvre pas encore
// (la CI le lance) : sans cela, une classe ajoutée à une vue sans relancer ce
// script ressortirait sans style, et personne ne le verrait.
//
// Limite connue : seuls les attributs class="..." littéraux sont lus. Une classe
// posée par :class, @class, x-bind:class ou depuis PHP (->extraAttributes) doit
// figurer aussi dans un class="..." quelque part, ou être ajoutée à la main
// dans manquantes.txt avant de relancer.

import { execFileSync } from 'node:child_process';
import { readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const racine = process.cwd();
const ici = join(racine, 'scripts/utilitaires-admin');
const cssFilament = readFileSync(join(racine, 'public/css/filament/filament/app.css'), 'utf8');

const vues = (dossier, acc = []) => {
    for (const nom of readdirSync(dossier)) {
        const chemin = join(dossier, nom);
        if (statSync(chemin).isDirectory()) vues(chemin, acc);
        else if (chemin.endsWith('.blade.php')) acc.push(chemin);
    }
    return acc;
};

// Classes propres au projet (namespaces CSS maison), jamais du Tailwind.
const maison = /^(fi-|gp-|ga-|cell-|x-)/;
const echapper = (c) => '.' + c.replace(/([:/.[\]%#])/g, '\\$1');
const connueDeFilament = (c) => [' ', '{', ':', ','].some((suite) => cssFilament.includes(echapper(c) + suite));

const manquantes = new Set();
for (const vue of vues(join(racine, 'resources/views/filament'))) {
    const source = readFileSync(vue, 'utf8');
    for (const [, attribut] of source.matchAll(/class="([^"]*)"/g)) {
        for (const c of attribut.replace(/\{\{.*?\}\}|@\w+\([^)]*\)/g, ' ').split(/[\s'"]+/)) {
            if (!c || maison.test(c) || !/^[a-z0-9:\-/.[\]%#]+$/.test(c)) continue;
            if (!connueDeFilament(c)) manquantes.add(c);
        }
    }
}

const liste = [...manquantes].sort().join('\n') + '\n';
const fichierListe = join(ici, 'manquantes.txt');

if (process.argv.includes('--verifier')) {
    // Comparer à la liste committée avec la feuille : une vue qui gagne une
    // classe sans que la feuille soit régénérée fait échouer la CI.
    const couverte = readFileSync(fichierListe, 'utf8').replace(/\r\n/g, '\n');
    if (couverte !== liste) {
        console.error('Des vues emploient des classes Tailwind que public/css/klassci-admin-utilities.css ne couvre pas.');
        console.error('Relancez : node scripts/utilitaires-admin/generer.mjs, puis committez le CSS et manquantes.txt.');
        process.exit(1);
    }
    console.log(`${manquantes.size} classes couvertes, feuille à jour.`);
    process.exit(0);
}

writeFileSync(fichierListe, liste);

const sortie = join(racine, 'public/css/klassci-admin-utilities.css');
execFileSync(
    process.platform === 'win32' ? 'pnpm.cmd' : 'pnpm',
    ['dlx', 'tailwindcss@3.4.17', '-c', join(ici, 'tailwind.config.cjs'), '-i', join(ici, 'entree.css'), '-o', sortie, '--minify'],
    { stdio: 'inherit', cwd: ici, shell: process.platform === 'win32' },
);

const entete = '/* Généré par scripts/utilitaires-admin/generer.mjs — ne pas modifier à la main. */\n';
writeFileSync(sortie, entete + readFileSync(sortie, 'utf8'));
console.log(`${manquantes.size} classes absentes de Filament → ${sortie}`);
