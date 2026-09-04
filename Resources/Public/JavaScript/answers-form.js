/**
 * The "Answers" edit form: the language field follows the site field.
 *
 * A language id is a site setting, not a global one — language 1 is French on
 * one website and Italian on the next. Offering every id of the installation
 * regardless of the chosen site would therefore invite exactly the combination
 * that never matches anything: an answer filed under a language its site does
 * not have, silently never played back.
 *
 * The map of site => languages is rendered into the site field as JSON, so
 * changing the selection needs no request.
 *
 * Degrades quietly: without the map, or with a site it does not know, the
 * language field keeps the options it was rendered with.
 */

const ALL_SITES = '';

class SiteLanguages {
    constructor(siteField) {
        this.siteField = siteField;
        this.languageField = document.querySelector('[data-wn-ai-language-field]');
        this.languages = SiteLanguages.readMap(siteField);

        if (this.languageField === null || this.languages === null) {
            return;
        }

        this.siteField.addEventListener('change', () => this.update());
    }

    static readMap(siteField) {
        try {
            const parsed = JSON.parse(siteField.dataset.wnAiLanguages || '{}');
            return typeof parsed === 'object' && parsed !== null ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    update() {
        const forSite = this.languages[this.siteField.value] ?? this.languages[ALL_SITES];
        if (forSite === undefined) {
            return;
        }

        // Keep what was selected where the new site also has it. Changing the
        // site of an answer is not a reason to silently move it to another
        // language.
        const previous = this.languageField.value;

        this.languageField.replaceChildren();
        for (const [languageId, label] of Object.entries(forSite)) {
            const option = document.createElement('option');
            option.value = languageId;
            option.textContent = label;
            option.selected = languageId === previous;
            this.languageField.append(option);
        }

        // Nothing carried over: fall back to the first the site offers rather
        // than leaving the field on a language that is not in the list.
        if (this.languageField.selectedIndex < 0 && this.languageField.options.length > 0) {
            this.languageField.selectedIndex = 0;
        }
    }
}

document.querySelectorAll('[data-wn-ai-site-field]').forEach((field) => new SiteLanguages(field));
