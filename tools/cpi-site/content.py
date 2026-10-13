# Page copy for canpatentit.com. Keep claims factual and non-promissory: no statistics,
# client names, testimonials or outcome guarantees unless the owner supplies verified facts.

SERVICES = [
    {
        "slug": "patentability-prior-art", "icon": "search", "short": "Patentability & prior art",
        "title": "Patentability & Prior Art Search",
        "desc": "Understand what is already out there, where your invention is distinct, and what to protect next.",
        "lead": "Before you spend on a filing, find out where your idea sits among what has already been published and patented.",
        "sections": [
            ("What this is", "<p>A patentability review compares your invention with published patents and technical literature. The goal is a clear picture of what looks new, what looks crowded, and which features are worth building a filing around.</p><p>No search is exhaustive and no review can promise an outcome at the patent office. What it can do is replace guesswork with context before you commit budget.</p>"),
            ("How it works", "<ol><li><strong>Technical conversation.</strong> We start with how the system actually works and what you believe is different.</li><li><strong>Search.</strong> We look across patent databases and technical literature using the concepts, not only the keywords, of your approach.</li><li><strong>Findings.</strong> You receive a plain summary of the closest references and where your approach appears to differ.</li><li><strong>Next step.</strong> We discuss whether to file, what to emphasise, or what to build or disclose differently.</li></ol>"),
        ],
        "bullets": ("Useful when", ["You are deciding whether a first filing is worth it", "A competitor or paper looks close to your approach", "An investor has asked about novelty", "You want to shape the roadmap around protectable features"]),
        "faq": [("Does a search guarantee a patent will be granted?", "No. A search informs your decision, but examination by the patent office is a separate process and the outcome cannot be guaranteed."), ("Should I search before I publish or demo?", "Ideally yes. Public disclosure can affect your rights, so speak with us before you publish, present or launch.")],
    },
    {
        "slug": "provisional-applications", "icon": "file-search", "short": "Provisional applications",
        "title": "Provisional Patent Applications",
        "desc": "Establish an early US filing date with a technical disclosure built around your real invention.",
        "lead": "A provisional application can secure an early filing date while you keep building, testing and raising.",
        "sections": [
            ("What a provisional does", "<p>A US provisional application establishes a filing date for what it adequately describes. It is not examined and does not become a patent by itself. To keep the benefit of that date you generally need to file a non-provisional application within twelve months.</p><p>Its value depends on the quality of the disclosure. A thin description protects little, so we build it from your real architecture, variants and fallback positions.</p>"),
            ("What we put into it", "<ul><li>The core system, method or composition, described in enough technical detail to support later claims</li><li>Alternative implementations and ranges, so the filing is not narrower than your thinking</li><li>Figures and flow diagrams that match how engineers explain the work</li><li>Early claim-style statements to anchor what matters commercially</li></ul>"),
        ],
        "bullets": ("Good moments to consider one", ["Before a public launch, paper, demo or investor deck", "When a prototype shows the approach works", "When the roadmap is moving faster than a full application", "When you need an early date and have a limited budget"]),
        "faq": [("What happens after twelve months?", "A provisional expires. If you want continued protection you need to file a non-provisional (and any international filings) within the applicable deadlines. We track the dates with you."), ("Can I say 'patent pending' after filing?", "Generally yes, once an application is on file. We can confirm how to use the term correctly for your situation.")],
    },
    {
        "slug": "us-patent-prosecution", "icon": "shield", "short": "US patent prosecution",
        "title": "US Patent Prosecution",
        "desc": "Develop and prosecute US applications with the technical detail and strategy your invention deserves.",
        "lead": "Prosecution is where claims meet an examiner. Good strategy keeps the technology, the business and the budget in view.",
        "sections": [
            ("From filing to decision", "<p>After a non-provisional application is filed it is examined at the US Patent and Trademark Office. Examiners commonly issue office actions raising objections or rejections, and the application proceeds through responses, interviews and sometimes appeals or continuations.</p><p>Each step is a chance to refine the claims toward what actually protects your product and your position with investors and partners.</p>"),
            ("How we approach it", "<ul><li><strong>Claims with intent.</strong> Claim sets drafted around your product and likely competitors, not only the first idea.</li><li><strong>Technical responses.</strong> Office action responses that engage with the references and the engineering.</li><li><strong>Portfolio thinking.</strong> Continuations and related filings planned alongside the roadmap.</li><li><strong>Clear updates.</strong> Plain-language status and decisions you can act on.</li></ul>"),
        ],
        "bullets": ("This fits teams who", ["Have a provisional approaching its twelve-month date", "Received an office action and need a technical response", "Want a claim strategy tied to the product", "Are preparing for financing or diligence"]),
        "faq": [("How long does prosecution take?", "It varies widely with the technology area and the examiner's workload. We cannot promise timing, but we can explain what to expect and options for speeding things up where available."), ("Can you handle filings outside the US?", "This site focuses on US patent strategy. If you need international protection we can discuss how to plan the deadlines and coordinate with local counsel.")],
    },
    {
        "slug": "ip-strategy-diligence", "icon": "lock", "short": "IP strategy & diligence",
        "title": "IP Strategy & Diligence",
        "desc": "Build a portfolio that stands up to investor questions, product decisions and growth.",
        "lead": "IP should support the business: what you own, what is protectable, where the risks are, and what to do next.",
        "sections": [
            ("Strategy that follows the roadmap", "<p>We help you decide what to protect, what to keep as a trade secret, what to publish, and in what order, with the product, the market and the runway in view.</p><p>The aim is a portfolio that tells a coherent story rather than a pile of unrelated filings.</p>"),
            ("Diligence readiness", "<ul><li>A clear inventory of filings, inventions, ownership and assignments</li><li>Gaps and risks identified before an investor finds them</li><li>Plain explanations for the questions diligence teams usually ask about IP</li><li>Freedom-to-operate questions framed so you know what needs deeper analysis</li></ul><p>A full freedom-to-operate opinion is a distinct, scoped piece of work; we will tell you when one is warranted.</p>"),
        ],
        "bullets": ("Often helpful before", ["A seed, Series A or later financing", "A partnership, licence or acquisition discussion", "A major product launch", "Hiring or contracting engineers who will contribute inventions"]),
        "faq": [("Do you assess whether we infringe someone else's patent?", "We can flag areas of concern and recommend when a formal freedom-to-operate analysis makes sense. That is a separate, scoped engagement."), ("We have no filings yet. Is strategy still useful?", "Yes. Early decisions about disclosure, ownership and filing order are often the cheapest to get right.")],
    },
]

AREAS = [
    {
        "slug": "ai-machine-learning", "icon": "sparkles", "short": "AI & machine learning",
        "title": "Patents for AI & Machine Learning",
        "desc": "US patent strategy for models, inference, vision systems and data pipelines.",
        "lead": "AI inventions often sit between software, math and hardware. Framing them well matters.",
        "sections": [
            ("What tends to matter", "<p>Software and AI claims are examined closely under US eligibility law, so how the invention is described affects its prospects. We focus on the technical problem solved and the specific technical improvement: how data is represented, how training or inference is made faster, more accurate, more robust or more efficient, and how the system interacts with real hardware or processes.</p>"),
            ("Typical subject matter", "<ul><li>Model architectures, training methods and data-handling pipelines</li><li>On-device and edge inference, compression and acceleration</li><li>Computer vision, sensing and signal processing</li><li>Systems that combine models with control, robotics or devices</li></ul>"),
        ],
        "bullets": ("Questions we start with", ["What technical problem does the system solve?", "What changed compared to the standard approach?", "What is measurable about the improvement?", "Which parts are implemented in your product today?"]),
        "faq": [("Can an AI model or algorithm be patented?", "It depends on how the invention is framed and what technical improvement it provides. We assess this case by case; no outcome can be promised.")],
    },
    {
        "slug": "semiconductors-quantum", "icon": "cpu", "short": "Semiconductors & quantum",
        "title": "Patents for Semiconductors & Quantum",
        "desc": "US patent strategy for chip architecture, materials, fabrication and quantum hardware.",
        "lead": "Hardware inventions reward careful claim structure, and the details of the process often are the invention.",
        "sections": [
            ("What tends to matter", "<p>In chips and quantum systems the protectable detail may live in a device structure, a process step, a material choice, an architecture or a control method. We work to capture each layer, so a competitor cannot design around a single narrow claim.</p>"),
            ("Typical subject matter", "<ul><li>Device structures, memory and compute architectures</li><li>Fabrication processes, packaging and materials</li><li>Interconnects, power management and accelerators</li><li>Qubit hardware, control electronics and error-handling methods</li></ul>"),
        ],
        "bullets": ("Questions we start with", ["Which layer holds the real advantage: device, process, material or architecture?", "What are the practical variants and operating ranges?", "What would a competitor change first to avoid your approach?", "What is already public in papers or talks?"]),
        "faq": [("When should I file relative to a conference paper?", "Before you publish or present. Public disclosure can limit your rights, particularly outside the US, so talk to us first.")],
    },
    {
        "slug": "medtech-biotechnology", "icon": "gauge", "short": "MedTech & biotechnology",
        "title": "Patents for MedTech & Biotechnology",
        "desc": "US patent strategy for diagnostics, biosensors, devices and computational biology.",
        "lead": "Regulated, research-driven technology needs IP that fits clinical, regulatory and funding timelines.",
        "sections": [
            ("What tends to matter", "<p>Diagnostic and life-science inventions face their own eligibility and disclosure questions. We look at how methods, devices and data analysis are described, and how filing timing interacts with studies, publications and regulatory work.</p>"),
            ("Typical subject matter", "<ul><li>Diagnostic devices, biosensors and wearable monitoring</li><li>Instrument and assay design, and associated methods</li><li>Computational biology and analysis pipelines</li><li>Systems combining sensors, software and clinical workflows</li></ul>"),
        ],
        "bullets": ("Questions we start with", ["Where is the technical advance: hardware, method, data or workflow?", "What are the study and publication timelines?", "What has already been disclosed to partners or reviewers?", "How will this be funded and regulated?"]),
        "faq": [("Do you give regulatory or medical advice?", "No. We focus on IP strategy and will coordinate with your regulatory and clinical advisers.")],
    },
    {
        "slug": "climate-energy", "icon": "arrow-down", "short": "Climate & energy",
        "title": "Patents for Climate & Energy",
        "desc": "US patent strategy for storage, grid systems, electrification and clean-energy hardware.",
        "lead": "Energy technology moves from lab to field. Your IP plan should move with it.",
        "sections": [
            ("What tends to matter", "<p>Energy inventions often combine materials, hardware, control software and system integration. Protecting each layer, and planning around pilots and partner conversations, helps keep options open as the technology scales.</p>"),
            ("Typical subject matter", "<ul><li>Battery and storage materials, cells and management systems</li><li>Grid, power-electronics and charging systems</li><li>Generation, conversion and efficiency improvements</li><li>Control and optimisation methods for energy systems</li></ul>"),
        ],
        "bullets": ("Questions we start with", ["What performance gain does the approach deliver, and under what conditions?", "Which pilots or partners will see the details, and under what agreements?", "What manufacturing or integration steps are distinctive?", "What do incumbents already patent nearby?"]),
        "faq": [("We are sharing details with a pilot partner. Is that a problem?", "It can be. Disclosure terms and timing matter, so talk to us before details leave the building.")],
    },
    {
        "slug": "enterprise-software-security", "icon": "lock", "short": "Enterprise software & security",
        "title": "Patents for Enterprise Software & Security",
        "desc": "US patent strategy for cloud systems, cryptography, infrastructure and security technology.",
        "lead": "Software patents hinge on the technical improvement. We help you describe it in a way that holds up.",
        "sections": [
            ("What tends to matter", "<p>For infrastructure and security inventions we focus on the concrete technical benefit: reduced latency, stronger guarantees, lower resource use, new capabilities of the system itself. The description needs to tie that benefit to how the system is built and operates.</p>"),
            ("Typical subject matter", "<ul><li>Distributed systems, orchestration and data infrastructure</li><li>Cryptographic protocols and key-management approaches</li><li>Authentication, detection and response techniques</li><li>Developer tooling and platform architecture</li></ul>"),
        ],
        "bullets": ("Questions we start with", ["What does the system do that it could not do before?", "Where does the improvement show up: speed, security, scale or cost?", "Which components are open source or already public?", "What would a competitor implement differently?"]),
        "faq": [("Is open-source involvement a problem for patenting?", "Not automatically, but licences, public repositories and disclosures all matter. We review them with you before filing.")],
    },
]

FAQS = [
    ("What is CanPatentIt?", "CanPatentIt is a US patent strategy practice for deep-technology founders, engineers and investors. We help you decide what to protect and how, from first idea to funding diligence."),
    ("Is the information on this site legal advice?", "No. The site gives general information only. Using it, taking the self-check or booking a call does not create an attorney-client relationship. An engagement begins only when we agree in writing."),
    ("Can you promise my invention will be patented?", "No one can. Patent offices decide, and outcomes depend on the facts and the prior art. We can help you make informed decisions and present your invention well."),
    ("When should I contact you?", "Ideally before you publish, demo, pitch publicly or launch, because public disclosure can affect your rights. Early conversations are often the cheapest way to avoid problems."),
    ("What should I bring to a first conversation?", "Just a description of the problem you solve and how your approach works. A rough diagram or draft deck helps. Keep very sensitive details high-level until we have agreed how to exchange confidential material."),
    ("What is a provisional patent application?", "A US filing that secures an early date for what it describes. It is not examined and expires after twelve months, so a non-provisional filing is needed to continue."),
    ("Do you work outside the US?", "Our focus is US patent strategy. For international protection we can help plan deadlines and coordinate with local counsel."),
    ("Which technologies do you work with?", "AI and machine learning, semiconductors and quantum, MedTech and biotechnology, climate and energy, and enterprise software and security."),
    ("How do fees work?", "Scope and fees depend on the work. We discuss them openly before anything starts. This site does not list prices."),
    ("How is my information handled?", "Details you send through our forms are used to respond to you. See the Privacy Policy for specifics."),
]

TECH_NOTE = "Information on this page is general and not legal advice. Outcomes cannot be guaranteed."
