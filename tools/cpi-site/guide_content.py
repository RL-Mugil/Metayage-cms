# DRAFT content for the flagship guide and four new FAQ answers. Nothing here is published until
# GUIDE_APPROVED is set to True after the site owner has reviewed the wording.
# General information about US patents only; no outcomes, prices or time-to-grant figures.

import os

# Set to True only after the owner approves the text. CPI_GUIDE_PREVIEW=1 builds it locally for review without publishing.
GUIDE_APPROVED = os.environ.get("CPI_GUIDE_PREVIEW") == "1"

GUIDE = {
    "slug": "can-i-patent-my-idea",
    "title": "Can I Patent My Idea? A Guide for Founders",
    "desc": "Can you patent it? What the US patent office looks for, what cannot be patented, how software and AI are treated, and what to do before you demo or publish.",
    "date": "2026-10-16",
    "lead": "Maybe. A US patent protects a specific, new and non-obvious invention that is described clearly enough for others to build it. A bare idea is not enough, but the way you make the idea work often is.",
    "sections": [
        ("The short answer",
         "<p>A US patent can protect a process, a machine, a manufactured article, a composition of matter, or a new improvement of one of these. It does not protect an idea on its own. What you can protect is a concrete way of doing something: how the system is built, how the steps run, how the material is made.</p>"
         "<p>So the useful question is not <em>&ldquo;Can I patent my idea?&rdquo;</em> but <em>&ldquo;Which parts of how my technology works are new, and can they be described precisely?&rdquo;</em></p>"),
        ("What the patent office looks for",
         "<ul><li><strong>It must be the right kind of thing.</strong> Inventions are eligible; laws of nature, natural phenomena and abstract ideas, taken alone, are not.</li>"
         "<li><strong>It must be new.</strong> If the same invention was public before your filing date, in a paper, a product, a talk or someone else&rsquo;s patent, it is not new.</li>"
         "<li><strong>It must not be obvious.</strong> Even if no single earlier document shows it, an examiner can combine earlier work to argue that a skilled person would have arrived at it.</li>"
         "<li><strong>It must be described well enough.</strong> The application has to explain the invention so that a skilled person could make and use it.</li></ul>"),
        ("What usually cannot be patented",
         "<ul><li>Abstract ideas, mathematical formulas and methods of organising human activity, when claimed without a specific technical implementation</li>"
         "<li>Natural phenomena and naturally occurring things as such</li>"
         "<li>Anything that was already public before you filed</li>"
         "<li>Something that is only a small, predictable variation of what already exists</li></ul>"),
        ("Software and AI",
         "<p>Software and AI inventions can be patentable in the US, but eligibility is one of the most closely examined areas. Doing something on a computer is not enough by itself. Claims tend to fare better when they describe a specific technical solution to a technical problem, for example a particular way of structuring data, training or running a model, or improving how a system performs.</p>"
         "<p>Because the outcome depends heavily on the details, this is a good area to talk through before you decide what to file.</p>"),
        ("Timing and public disclosure",
         "<p>Be careful before you demo, publish a paper, open-source code, pitch publicly or launch. Your own public disclosure can count against you.</p>"
         "<ul><li>The US generally gives inventors a <strong>12-month grace period</strong> after their own disclosure.</li>"
         "<li>Many other countries give <strong>no grace period</strong>, so rights there can be lost the moment you disclose.</li>"
         "<li>A <a href=\"/services/provisional-applications\">provisional application</a> can secure an early filing date while you keep building. It is not examined and expires after 12 months.</li></ul>"
         "<p>You can see the dates with our <a href=\"/tools/deadlines\">deadline calculators</a>.</p>"),
        ("How do I know if someone already patented it?",
         "<p>Patent documents are public, and you can search them yourself in free databases. A careful search goes further than keywords: the same idea may be described in different words, and earlier papers, products and open-source projects count as prior art too.</p>"
         "<p>A <a href=\"/services/patentability-prior-art\">patentability and prior-art search</a> looks at what is close to your invention and what is genuinely different. That is separate from a freedom-to-operate analysis, which asks whether selling your product could infringe someone else&rsquo;s patent.</p>"),
        ("What to do first",
         "<ol><li>Write down how the technology works, including the alternatives you considered.</li>"
         "<li>List every date you showed, published or sold anything related to it.</li>"
         "<li>Hold off on public disclosure until you have decided what to protect.</li>"
         "<li>Try the <a href=\"/patentability-check\">patentability self-check</a> for plain-language guidance.</li>"
         "<li>Talk it through with someone who understands the technology and the patent system.</li></ol>"),
    ],
    "faq": [
        ("Can I patent my idea?", "Not an idea on its own. You can protect a specific, new and non-obvious way of making the idea work, if it is described clearly enough for others to build it."),
        ("Can software or AI be patented in the US?", "Often yes, but eligibility is closely examined. Claims about a specific technical solution to a technical problem tend to fare better than claims that only apply an idea on a computer."),
        ("Can I still patent something I have already shown publicly?", "In the US, an inventor generally has 12 months after their own public disclosure to file. Many other countries allow no such period, so rights there may already be affected."),
        ("How do I know if my invention is already patented?", "Patent documents are public and searchable, but a careful search also covers papers, products and open-source work described in different words. A professional prior-art search can show what is close and what is different."),
    ],
}
