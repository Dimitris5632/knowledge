<svg class="meander" viewBox="0 0 400 20" preserveAspectRatio="none" aria-hidden="true">
    <defs>
        <pattern id="meander-<?= $meander_id ?? 'a' ?>" width="20" height="20" patternUnits="userSpaceOnUse">
            <path d="M0 18 H18 V2 H5 V13 H13 V7 H9" fill="none" stroke="currentColor" stroke-width="1.6"/>
        </pattern>
    </defs>
    <rect width="400" height="20" fill="url(#meander-<?= $meander_id ?? 'a' ?>)"/>
</svg>
