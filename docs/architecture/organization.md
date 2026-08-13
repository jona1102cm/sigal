# Organización institucional

Estado: **implementado conforme al organigrama institucional y listo para la carga de funcionarios desde Recursos Humanos**.

## Modelo

- Las unidades del organigrama forman un árbol recursivo mediante `offices.parent_id`; la dependencia jerárquica es el dato que define bajo qué unidad se encuentra cada nodo.
- Cada nodo tiene un código interno único, nombre y estado `active` o `inactive`.
- Las oficinas se clasifican según dos reglas explícitas: si admiten funcionarios y si requieren responsable de oficina.
- Un nodo sin funcionarios tampoco admite cargos, contratos ni membresías. Esto conserva en el mismo árbol a las instancias representativas sin convertirlas artificialmente en oficinas operativas.
- Una oficina con funcionarios y responsable requerido recibe en el catálogo un cargo de jefatura inicial, sin asignarle un titular hasta que Recursos Humanos registre el contrato correspondiente.
- Una oficina no puede depender de sí misma ni de una descendiente; tampoco puede depender de una oficina inactiva. La inactivación exige que no tenga suboficinas ni membresías vigentes.

## Organigrama oficial cargado

- **Pleno Asamblea Legislativa** es el nodo institucional raíz y no administra funcionarios.
- Del Pleno dependen **Asesores del Pleno** —oficina con funcionarios sin responsable propio— y **Directiva**, nodo representativo sin funcionarios.
- De Directiva dependen Primera Vicepresidencia, Primera Secretaría y Presidencia de Directiva. Presidencia concentra Asesores de Presidencia —oficina con funcionarios sin responsable propio—, Oficialía Mayor Administrativa y Financiera (OMAF), Secretaría General y Protocolo, Comisiones y Bancadas.
- De OMAF dependen Unidad de Administración Financiera, Unidad Jurídica, Unidad de Recursos Humanos, Sección de Servicios Informáticos y Sección de Comunicación. La Unidad de Administración Financiera concentra Contabilidad y Cierre, Presupuesto y Planificación, Tesorería, Bienes y Servicios, Responsable de Elaboración de Planillas y, bajo Bienes y Servicios, Activos Fijos y Almacenes.
- De Secretaría General y Protocolo depende Registro y Archivo Institucional.
- Comisiones y Bancadas son nodos agrupadores sin funcionarios ni responsable de oficina. Cada una de sus siete comisiones y siete bancadas dependientes es una oficina operativa, con funcionarios y responsable propio.

La Primera Vicepresidencia, Primera Secretaría, Presidencia, OMAF, Secretaría General, comisiones, bancadas, unidades y secciones operativas sí requieren responsable de oficina. Las dos asesorías indicadas son las únicas excepciones con funcionarios pero sin responsable propio, conforme al organigrama aprobado.

## Membresías, cargos y seguridad

- `office_memberships` conserva la relación histórica entre usuario y oficina, con inicio, cierre y responsable de la asignación.
- Recursos Humanos es la fuente de cargos y contratos. Solo presenta oficinas activas que admiten funcionarios y sus cargos correspondientes.
- El cargo puede indicar función `manager` o `official`; un usuario puede conservar varias asignaciones históricas o simultáneas cuando su contratación lo requiera.
- Solo un superadministrador activo administra la estructura y sus membresías en esta primera etapa. Todas las modificaciones y cierres se auditan.
- Esta información es la fuente para la visibilidad de jefaturas, la recepción de derivaciones y los permisos de Archivo Central y OMAF en el motor de Expedientes.
