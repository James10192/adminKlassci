// Configuration lue par generer.mjs. Les couleurs lisent les variables que
// Filament pose déjà (--primary-500…), pour suivre la palette du panneau.
const nuances = [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950];
const palette = (nom) => Object.fromEntries(nuances.map((n) => [n, `rgba(var(--${nom}-${n}), <alpha-value>)`]));

module.exports = {
    content: [__dirname + '/manquantes.txt'],
    darkMode: 'class',
    corePlugins: { preflight: false },
    theme: {
        extend: {
            colors: {
                primary: palette('primary'),
                gray: palette('gray'),
                danger: palette('danger'),
                success: palette('success'),
                warning: palette('warning'),
                info: palette('info'),
            },
        },
    },
};
