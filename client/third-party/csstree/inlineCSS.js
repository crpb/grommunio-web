/*--------------------------------------------------------------------
 inlineCSS

 inlineCSS wrapper based on CSSTree

 For HTML-based emails, the most effective and widely used method to
 ensure proper display in a wide variety of email clients is to inline
 CSS designs into the to-be-applied html elements directly. This 
 function wraps around CSSTree to do the parsing and return the
 content properly inlined.
--------------------------------------------------------------------*/

function inlineCSS(content) {
    const doc = new DOMParser().parseFromString(content, 'text/html');
    const stylesheets = doc.querySelectorAll('style');
    const elements = doc.querySelectorAll('*');

    stylesheets.forEach(styleSheet => {
        const cssAST = CSSTree.parse(styleSheet.textContent);
        const rules = cssAST.children;

        rules.forEach(rule => {
            if (rule.type === 'Rule') {
                const selector = CSSTree.generate(rule.prelude);
                const matchingElements = doc.querySelectorAll(selector);

                matchingElements.forEach(element => {
                    const styleText = rule.block.children.map(decl => `${decl.property}: ${CSSTree.generate(decl.value)};`).join(' ');
                    element.style.cssText += styleText;
                });
            }
        });
    });

    stylesheets.forEach(styleSheet => styleSheet.remove());

    return doc.documentElement.outerHTML;
}
