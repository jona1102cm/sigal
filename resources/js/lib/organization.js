export function flattenOfficeHierarchy(offices) {
    const byParent = new Map();
    const visited = new Set();

    offices.forEach((office) => {
        const parentId = office.parent_id ?? null;
        if (!byParent.has(parentId)) byParent.set(parentId, []);
        byParent.get(parentId).push(office);
    });
    byParent.forEach((items) => items.sort((left, right) => left.name.localeCompare(right.name, 'es')));

    const flattened = [];
    const visit = (office, depth = 0) => {
        if (visited.has(office.id)) return;

        visited.add(office.id);
        flattened.push({
            ...office,
            depth,
            hierarchy_label: `${'— '.repeat(depth)}${office.code} · ${office.name}`,
        });

        for (const child of byParent.get(office.id) ?? []) {
            visit(child, depth + 1);
        }
    };

    for (const office of byParent.get(null) ?? []) {
        visit(office);
    }

    // El directorio operativo no incluye nodos estructurales sin personal.
    // Cuando uno de ellos es ancestro de una oficina operativa, se muestra esa
    // oficina como raíz para no perderla de los selectores del sistema.
    for (const office of [...offices].sort((left, right) => left.name.localeCompare(right.name, 'es'))) {
        visit(office);
    }

    return flattened;
}
